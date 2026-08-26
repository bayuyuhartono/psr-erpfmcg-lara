<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Exceptions\NothingToExchange;
use App\Domain\Finance\Models\InvoiceExchange;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Sales\Models\SalesInvoice;
use App\Models\User;
use App\Support\Posting\DocumentNumberer;
use App\Support\Posting\PostingTransaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Gathers the invoices due to be handed over on a customer's exchange day
 * (F-50).
 *
 * This is the step a general accounting package has no concept of. An outlet
 * does not pay when an invoice falls due; it accepts invoices on its exchange
 * day, and pays on its payment day, and a distributor who turns up on the
 * wrong day goes home with nothing. `invoice_exchange_days` is why the system
 * knows which day that is.
 */
final class AssembleInvoiceExchange
{
    public function __construct(private readonly DocumentNumberer $numberer) {}

    public function __invoke(
        BusinessPartner $customer,
        ?DateTimeInterface $on = null,
        ?DateTimeInterface $promisedPaymentDate = null,
        ?User $actor = null,
    ): InvoiceExchange {
        $on = CarbonImmutable::instance($on ?? CarbonImmutable::now())->startOfDay();

        $invoices = SalesInvoice::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', 'posted')
            ->whereDate('invoice_date', '<=', $on->format('Y-m-d'))
            ->orderBy('invoice_date')
            ->get();

        if ($invoices->isEmpty()) {
            throw new NothingToExchange(sprintf(
                '%s has no invoices waiting to be handed over on %s.',
                $customer->name,
                $on->format('Y-m-d'),
            ));
        }

        return PostingTransaction::run(function () use ($customer, $invoices, $on, $promisedPaymentDate): InvoiceExchange {
            $exchange = InvoiceExchange::create([
                'document_number' => $this->numberer->next('EXC', $on),
                'customer_id' => $customer->getKey(),
                'exchange_date' => $on->format('Y-m-d'),
                'promised_payment_date' => ($promisedPaymentDate ?? $this->nextPaymentDay($customer, $on))?->format('Y-m-d'),
                'total_amount' => $invoices
                    ->reduce(fn (BigDecimal $carry, SalesInvoice $invoice) => $carry->plus($invoice->total), BigDecimal::zero())
                    ->toScale(2, RoundingMode::HALF_UP),
            ]);

            $exchange->salesInvoices()->attach($invoices->pluck('id')->all());

            return $exchange->refresh();
        });
    }

    /** Hands the batch over and marks the invoices as being with the customer. */
    public function handOver(InvoiceExchange $exchange, string $receivedBy, ?User $actor = null): InvoiceExchange
    {
        return PostingTransaction::run(function () use ($exchange, $receivedBy, $actor): InvoiceExchange {
            $exchange->transitionTo('handed_over', $actor, ['received_by' => $receivedBy]);

            foreach ($exchange->salesInvoices()->get() as $invoice) {
                if ($invoice->canTransitionTo('exchanged')) {
                    $invoice->transitionTo('exchanged', $actor);
                }
            }

            return $exchange->refresh();
        });
    }

    /**
     * The customer's next payment day. Falls back to the invoice terms when
     * the outlet has no schedule of its own.
     */
    private function nextPaymentDay(BusinessPartner $customer, CarbonImmutable $on): ?CarbonImmutable
    {
        $days = collect($customer->payment_days ?? [])->sort()->values();

        if ($days->isEmpty()) {
            return $customer->payment_terms_days
                ? $on->addDays((int) $customer->payment_terms_days)
                : null;
        }

        $next = $days->first(fn (int $day): bool => $day > $on->day);

        return $next === null
            ? $on->addMonthNoOverflow()->startOfMonth()->addDays($days->first() - 1)
            : $on->startOfMonth()->addDays($next - 1);
    }
}
