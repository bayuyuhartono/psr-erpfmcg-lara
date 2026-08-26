<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Exceptions\InvalidPayment;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Sales\Models\SalesInvoice;
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
 * Takes money in and decides which invoices it closes (F-52).
 *
 *   DR 1101 Cash and Bank
 *      CR 1102 Trade Receivables    what it was allocated against
 *      CR 2104 Customer Advances    whatever was left over
 *
 * One payment can close several invoices and one invoice can be paid in
 * instalments, because that is how outlets actually settle — a round number
 * against whatever is oldest, then the rest next time.
 *
 * Anything unallocated is held as an advance rather than booked as income. It
 * is still the customer's money until there is an invoice for it to answer.
 */
final class RecordCustomerPayment
{
    public function __construct(
        private readonly JournalPoster $journals,
        private readonly PostingGuard $guard,
        private readonly DocumentNumberer $numberer,
    ) {}

    /** @param  array<string, string>  $allocations  invoice ID => amount applied */
    public function __invoke(
        BusinessPartner $customer,
        BigDecimal|string $amount,
        array $allocations = [],
        string $method = 'transfer',
        ?DateTimeInterface $paidOn = null,
        ?User $actor = null,
        ?string $cashAccountId = null,
    ): Payment {
        $amount = BigDecimal::of($amount)->toScale(2, RoundingMode::HALF_UP);
        $paidOn = CarbonImmutable::instance($paidOn ?? CarbonImmutable::now())->startOfDay();

        if (! $amount->isPositive()) {
            throw new InvalidPayment('A payment must be for more than zero.');
        }

        $allocated = $this->totalOf($allocations);

        if ($allocated->isGreaterThan($amount)) {
            throw new InvalidPayment(sprintf(
                'Allocating %s of a %s payment. Money cannot be applied twice.',
                $allocated,
                $amount,
            ));
        }

        return PostingTransaction::run(function () use ($customer, $amount, $allocations, $allocated, $method, $paidOn, $actor, $cashAccountId): Payment {
            $customer->refresh();

            $payment = Payment::create([
                'document_number' => $this->numberer->next('RCP', $paidOn),
                'direction' => 'inbound',
                'partner_id' => $customer->getKey(),
                'payment_date' => $paidOn->format('Y-m-d'),
                'method' => $method,
                'amount' => $amount,
                'cash_account_id' => $cashAccountId,
                'collected_by' => $actor?->getKey(),
            ]);

            foreach ($allocations as $invoiceId => $applied) {
                $this->applyTo($payment, $invoiceId, BigDecimal::of($applied)->toScale(2, RoundingMode::HALF_UP), $actor);
            }

            $payment->transitionTo('posted', $actor);
            $this->guard->claim('payment', $payment->getKey(), $actor?->getKey());

            $advance = $amount->minus($allocated);
            $postings = [JournalPosting::debit('cash', $amount, partnerId: $customer->getKey())];

            if ($allocated->isPositive()) {
                $postings[] = JournalPosting::credit('receivable', $allocated, partnerId: $customer->getKey());
            }

            if ($advance->isPositive()) {
                $postings[] = JournalPosting::credit('advance', $advance, partnerId: $customer->getKey());
            }

            $this->journals->post(new JournalDocument(
                documentType: 'payment',
                referenceId: $payment->getKey(),
                entryDate: $paidOn,
                postings: $postings,
                description: sprintf('Payment %s from %s', $payment->document_number, $customer->name),
                actorId: $actor?->getKey(),
            ));

            return $payment->refresh();
        });
    }

    private function applyTo(Payment $payment, string $invoiceId, BigDecimal $applied, ?User $actor): void
    {
        /** @var SalesInvoice $invoice */
        $invoice = SalesInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
        $owing = $invoice->total->minus($invoice->paid_amount);

        if ($applied->isGreaterThan($owing)) {
            throw new InvalidPayment(sprintf(
                'Applying %s to invoice %s, which is only owed %s. The rest is an advance, not an overpayment of this invoice.',
                $applied,
                $invoice->document_number,
                $owing,
            ));
        }

        PaymentAllocation::create([
            'payment_id' => $payment->getKey(),
            'sales_invoice_id' => $invoice->getKey(),
            'amount' => $applied,
        ]);

        $paid = $invoice->paid_amount->plus($applied);
        $settled = $paid->isGreaterThanOrEqualTo($invoice->total);

        $invoice->transitionTo($settled ? 'paid' : 'partially_paid', $actor, ['paid_amount' => $paid]);
    }

    /** @param  array<string, string>  $allocations */
    private function totalOf(array $allocations): BigDecimal
    {
        return array_reduce(
            $allocations,
            fn (BigDecimal $carry, $applied): BigDecimal => $carry->plus(BigDecimal::of($applied)),
            BigDecimal::zero(),
        )->toScale(2, RoundingMode::HALF_UP);
    }
}
