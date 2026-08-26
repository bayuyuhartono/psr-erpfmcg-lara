<?php

namespace App\Support\Posting;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Support\Posting\Exceptions\ClosedPeriod;
use DateTimeInterface;

/**
 * Refuses anything dated into a closed accounting period (F-60).
 *
 * A month is closed once its figures have been reported. A transaction that
 * lands there afterwards changes a number someone has already acted on, and
 * nothing in the system would say so — which is why the PRD makes this a lock
 * rather than a warning.
 *
 * A month with no row is open. Locking is something a person does deliberately;
 * the absence of a decision is not a lock.
 */
final class PeriodGuard
{
    public function assertOpen(DateTimeInterface $date, string $what): void
    {
        if (! $this->isOpen($date)) {
            throw new ClosedPeriod(sprintf(
                '%s is dated %s, which falls in a closed accounting period. Post it in an open period, or reopen %s first.',
                $what,
                $date->format('Y-m-d'),
                $date->format('F Y'),
            ));
        }
    }

    public function isOpen(DateTimeInterface $date): bool
    {
        $period = AccountingPeriod::query()
            ->where('year', (int) $date->format('Y'))
            ->where('month', (int) $date->format('n'))
            ->first();

        return $period?->status !== 'closed';
    }
}
