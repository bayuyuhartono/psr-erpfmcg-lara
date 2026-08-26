<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Masters\Models\CompanySetting;
use App\Domain\Sales\Exceptions\InvalidSalesInvoice;
use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\DeliveryLine;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\SalesInvoiceLine;
use App\Models\User;
use App\Support\Posting\DocumentNumberer;
use App\Support\Posting\JournalDocument;
use App\Support\Posting\JournalPoster;
use App\Support\Posting\JournalPosting;
use App\Support\Posting\PostingGuard;
use App\Support\Posting\PostingTransaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Bills the outlet for what it received (F-42).
 *
 *   DR 1102 Trade Receivables
 *      CR 4101 Sales Revenue
 *      CR 2102 VAT Payable
 *
 * Cost of goods was already recognised when the load left; this is the other
 * half. Quantities come from `qty_accepted`, prices from the order line the
 * delivery line came from — the snapshot taken when the customer agreed to
 * buy, not today's price list.
 *
 * Bonus lines invoice at zero and still appear, because an outlet that was
 * promised free goods needs to see them on the paperwork.
 */
final class InvoiceDelivery
{
    public function __construct(
        private readonly JournalPoster $journals,
        private readonly PostingGuard $guard,
        private readonly DocumentNumberer $numberer,
    ) {}

    public function __invoke(Delivery $delivery, ?User $actor = null): SalesInvoice
    {
        if ($delivery->status !== 'delivered') {
            throw new InvalidSalesInvoice(sprintf(
                'Delivery %s is %s. An invoice follows proof the goods arrived, not the other way round.',
                $delivery->document_number,
                $delivery->status,
            ));
        }

        $lines = $delivery->deliveryLines()->with(['product', 'salesOrderLine'])->get()
            ->filter(fn (DeliveryLine $line): bool => $line->qty_accepted !== null && $line->qty_accepted->isPositive());

        if ($lines->isEmpty()) {
            throw new InvalidSalesInvoice(sprintf('Delivery %s was not accepted, so there is nothing to bill.', $delivery->document_number));
        }

        $customer = $delivery->customer;
        $defaultVat = CompanySetting::query()->value('default_vat_percent') ?? '11';

        return PostingTransaction::run(function () use ($delivery, $lines, $customer, $defaultVat, $actor): SalesInvoice {
            // A deadlock retry re-runs this closure, and $delivery still
            // carries whatever the rolled-back attempt put on it.
            $delivery->refresh();

            $invoice = SalesInvoice::create([
                'document_number' => $this->numberer->next('INV', $delivery->delivery_date),
                'customer_id' => $customer->getKey(),
                'delivery_id' => $delivery->getKey(),
                'invoice_date' => $delivery->delivery_date->format('Y-m-d'),
                'due_date' => $delivery->delivery_date->addDays((int) ($customer->payment_terms_days ?? 0))->format('Y-m-d'),
                'subtotal' => '0.00', 'total' => '0.00',
            ]);

            $subtotal = BigDecimal::zero();
            $discount = BigDecimal::zero();
            $vat = BigDecimal::zero();

            foreach ($lines as $line) {
                $orderLine = $line->salesOrderLine;
                $unitPrice = BigDecimal::of($orderLine?->unit_price ?? 0)->toScale(2);
                // The delivery line is in base units; the order was placed in
                // whatever unit the customer buys in, so bill in that one.
                $factor = BigDecimal::of($orderLine?->qty_in_base ?? 1);
                $quantity = $line->qty_accepted->dividedBy($factor->isZero() ? BigDecimal::one() : $factor, 4, RoundingMode::HALF_UP);

                $gross = $quantity->multipliedBy($unitPrice);
                $lineDiscount = $this->lineDiscount($orderLine, $gross);
                $net = $gross->minus($lineDiscount)->toScale(2, RoundingMode::HALF_UP);

                SalesInvoiceLine::create([
                    'sales_invoice_id' => $invoice->getKey(),
                    'delivery_line_id' => $line->getKey(),
                    'product_id' => $line->product_id,
                    'uom' => $orderLine?->uom ?? $line->product->base_uom,
                    'qty' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_amount' => $lineDiscount->toScale(2, RoundingMode::HALF_UP),
                    'line_total' => $net,
                ]);

                $subtotal = $subtotal->plus($gross);
                $discount = $discount->plus($lineDiscount);
                $rate = BigDecimal::of($line->product->vat_percent ?? $defaultVat);
                $vat = $vat->plus($net->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP));
            }

            $total = $subtotal->minus($discount)->plus($vat)->toScale(2, RoundingMode::HALF_UP);

            $invoice->forceFill([
                'subtotal' => $subtotal->toScale(2, RoundingMode::HALF_UP),
                'discount_amount' => $discount->toScale(2, RoundingMode::HALF_UP),
                'vat_amount' => $vat->toScale(2, RoundingMode::HALF_UP),
                'total' => $total,
            ])->save();

            $invoice->transitionTo('posted', $actor);
            $this->guard->claim('sales_invoice', $invoice->getKey(), $actor?->getKey());

            if ($total->isPositive()) {
                $postings = [
                    JournalPosting::debit('receivable', $total, partnerId: $customer->getKey()),
                    JournalPosting::credit('revenue', $subtotal->minus($discount)->toScale(2, RoundingMode::HALF_UP), partnerId: $customer->getKey()),
                ];

                if ($vat->isPositive()) {
                    $postings[] = JournalPosting::credit('vat_out', $vat->toScale(2, RoundingMode::HALF_UP), partnerId: $customer->getKey());
                }

                $this->journals->post(new JournalDocument(
                    documentType: 'sales_invoice',
                    referenceId: $invoice->getKey(),
                    entryDate: $delivery->delivery_date,
                    postings: $postings,
                    description: sprintf('Invoice %s', $invoice->document_number),
                    actorId: $actor?->getKey(),
                ));
            }

            return $invoice->refresh();
        });
    }

    private function lineDiscount($orderLine, BigDecimal $gross): BigDecimal
    {
        if ($orderLine === null) {
            return BigDecimal::zero();
        }

        $net = $gross;

        foreach ([$orderLine->discount1_percent, $orderLine->discount2_percent, $orderLine->discount3_percent] as $percent) {
            $percent = BigDecimal::of($percent ?? 0);

            if ($percent->isPositive()) {
                $net = $net->multipliedBy(BigDecimal::of(100)->minus($percent))->dividedBy(100, 6, RoundingMode::HALF_UP);
            }
        }

        return $gross->minus($net);
    }
}
