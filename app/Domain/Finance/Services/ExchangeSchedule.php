<?php

namespace App\Domain\Finance\Services;

use App\Domain\Masters\Models\BusinessPartner;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Which customers are due an invoice exchange today (F-50).
 *
 * Every outlet has its own days. Collections staff work from this list rather
 * than from invoice due dates, because turning up on the wrong day means
 * coming back next week.
 */
final class ExchangeSchedule
{
    /** @return Collection<int, BusinessPartner> */
    public function dueOn(?DateTimeInterface $on = null): Collection
    {
        $on = CarbonImmutable::instance($on ?? CarbonImmutable::now())->startOfDay();

        return BusinessPartner::query()
            ->customers()
            ->where('active', true)
            ->whereNotNull('invoice_exchange_days')
            ->get()
            ->filter(fn (BusinessPartner $customer): bool => in_array($on->day, (array) $customer->invoice_exchange_days, true))
            ->values();
    }
}
