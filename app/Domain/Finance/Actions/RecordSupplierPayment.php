<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Exceptions\InvalidPayment;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Purchasing\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Posting\DocumentNumberer;
use App\Support\Posting\JournalDocument;
use App\Support\Posting\JournalPoster;
use App\Support\Posting\JournalPosting;
use App\Support\Posting\PostingGuard;
use App\Support\Posting\PostingTransaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Pays a supplier.
 *
 *   DR 2101 Trade Payables      CR 1101 Cash and Bank
 *
 * The mirror of a customer payment, minus the advance: money paid beyond what
 * is owed is refused rather than parked, because a supplier holding our money
 * is a conversation, not a bookkeeping entry.
 */
final class RecordSupplierPayment
{
    public function __construct(
        private readonly JournalPoster $journals,
        private readonly PostingGuard $guard,
        private readonly DocumentNumberer $numberer,
    ) {}

    /** @param  array<string, string>  $allocations  supplier invoice ID => amount applied */
    public function __invoke(
        BusinessPartner $supplier,
        array $allocations,
        string $method = 'transfer',
        ?DateTimeInterface $paidOn = null,
        ?User $actor = null,
    ): Payment {
        if ($allocations === []) {
            throw new InvalidPayment('A supplier payment has to say which invoices it pays.');
        }

        $paidOn = CarbonImmutable::instance($paidOn ?? CarbonImmutable::now())->startOfDay();
        $amount = array_reduce(
            $allocations,
            fn (BigDecimal $carry, $applied): BigDecimal => $carry->plus(BigDecimal::of($applied)),
            BigDecimal::zero(),
        )->toScale(2, RoundingMode::HALF_UP);

        return PostingTransaction::run(function () use ($supplier, $allocations, $amount, $method, $paidOn, $actor): Payment {
            $supplier->refresh();

            $payment = Payment::create([
                'document_number' => $this->numberer->next('PMT', $paidOn),
                'direction' => 'outbound',
                'partner_id' => $supplier->getKey(),
                'payment_date' => $paidOn->format('Y-m-d'),
                'method' => $method,
                'amount' => $amount,
                'collected_by' => $actor?->getKey(),
            ]);

            foreach ($allocations as $invoiceId => $applied) {
                /** @var SupplierInvoice $invoice */
                $invoice = SupplierInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
                $applied = BigDecimal::of($applied)->toScale(2, RoundingMode::HALF_UP);
                $owing = $invoice->total->minus($invoice->paid_amount);

                if ($applied->isGreaterThan($owing)) {
                    throw new InvalidPayment(sprintf(
                        'Paying %s against invoice %s, which is only owed %s.',
                        $applied,
                        $invoice->document_number,
                        $owing,
                    ));
                }

                PaymentAllocation::create([
                    'payment_id' => $payment->getKey(),
                    'supplier_invoice_id' => $invoice->getKey(),
                    'amount' => $applied,
                ]);

                $paid = $invoice->paid_amount->plus($applied);

                if ($paid->isGreaterThanOrEqualTo($invoice->total)) {
                    $invoice->transitionTo('paid', $actor, ['paid_amount' => $paid]);
                } else {
                    $invoice->forceFill(['paid_amount' => $paid])->save();
                }
            }

            $payment->transitionTo('posted', $actor);
            $this->guard->claim('payment', $payment->getKey(), $actor?->getKey());

            $this->journals->post(new JournalDocument(
                documentType: 'supplier_payment',
                referenceId: $payment->getKey(),
                entryDate: $paidOn,
                postings: [
                    JournalPosting::debit('payable', $amount, partnerId: $supplier->getKey()),
                    JournalPosting::credit('cash', $amount, partnerId: $supplier->getKey()),
                ],
                description: sprintf('Payment %s to %s', $payment->document_number, $supplier->name),
                actorId: $actor?->getKey(),
            ));

            return $payment->refresh();
        });
    }
}
