<?php

namespace App\Domain\Sales\Services;

use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Sales\Models\SalesInvoice;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What a customer owes and whether they are past due (F-30).
 *
 * Outstanding is read from the invoices themselves rather than from a running
 * balance on the customer, because a balance that drifts from the invoices is
 * how a distributor keeps selling to someone who stopped paying months ago.
 */
final class CreditCheck
{
    private const OPEN = ['posted', 'exchanged', 'partially_paid'];

    public function __invoke(BusinessPartner $customer, ?DateTimeInterface $on = null): CreditStatus
    {
        $on = CarbonImmutable::instance($on ?? CarbonImmutable::now())->startOfDay();

        $open = SalesInvoice::query()
            ->where('customer_id', $customer->getKey())
            ->whereIn('status', self::OPEN)
            ->get(['total', 'paid_amount', 'due_date']);

        $outstanding = BigDecimal::zero();
        $overdue = BigDecimal::zero();
        $overdueCount = 0;

        foreach ($open as $invoice) {
            $unpaid = BigDecimal::of($invoice->total)->minus($invoice->paid_amount);

            if (! $unpaid->isPositive()) {
                continue;
            }

            $outstanding = $outstanding->plus($unpaid);

            if (CarbonImmutable::parse($invoice->due_date)->startOfDay()->lt($on)) {
                $overdue = $overdue->plus($unpaid);
                $overdueCount++;
            }
        }

        return new CreditStatus(
            BigDecimal::of($customer->credit_limit ?? 0)->toScale(2),
            $outstanding->toScale(2),
            $overdue->toScale(2),
            $overdueCount,
            (bool) $customer->on_hold,
            $customer->hold_reason,
        );
    }
}
