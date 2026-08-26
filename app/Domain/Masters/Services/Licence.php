<?php

namespace App\Domain\Masters\Services;

use App\Domain\Masters\Models\License;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What this installation was sold.
 *
 * **The software is sold, not rented.** It has no expiry and never stops
 * working. A distributor's receivables ledger, stock and journals live on
 * their own server, and holding those hostage over a renewal is not a
 * commercial lever — it is a reason to be sued and never referred.
 *
 * So there are exactly two things this enforces, and neither can lock anyone
 * out of work already done:
 *
 *   - **Seats.** Activating one more user than were bought is refused. The
 *     users who already work here keep working.
 *   - **Modules.** Features that were not bought are not shown.
 *
 * `supported_until` is when updates and support end. It is reported, never
 * enforced. An unsupported installation is an installation that stops
 * receiving improvements, not one that stops running.
 */
final class Licence
{
    public function current(): ?License
    {
        return License::query()->latest('issued_at')->first();
    }

    /** Null means unlimited: an installation with no licence row still runs. */
    public function seats(): ?int
    {
        $seats = $this->current()?->max_users;

        return $seats === null ? null : (int) $seats;
    }

    public function seatsUsed(): int
    {
        return User::query()->where('active', true)->count();
    }

    public function seatsLeft(): ?int
    {
        $seats = $this->seats();

        return $seats === null ? null : max(0, $seats - $this->seatsUsed());
    }

    /** Whether one more person can be activated. Never affects existing users. */
    public function hasSeatFor(?User $user = null): bool
    {
        $seats = $this->seats();

        if ($seats === null) {
            return true;
        }

        // Someone already active is not taking a new seat.
        if ($user !== null && $user->exists && $user->getOriginal('active')) {
            return true;
        }

        return $this->seatsUsed() < $seats;
    }

    public function allows(string $module): bool
    {
        $modules = $this->current()?->enabled_modules;

        // No licence row, or no module list, means everything. A missing
        // record must never be read as "nothing is permitted".
        return $modules === null || $modules === [] || in_array($module, (array) $modules, true);
    }

    public function supportedUntil(): ?CarbonImmutable
    {
        $until = $this->current()?->supported_until;

        return $until === null ? null : CarbonImmutable::parse($until);
    }

    /** For a banner, and nothing else. */
    public function supportHasLapsed(): bool
    {
        $until = $this->supportedUntil();

        return $until !== null && $until->isPast();
    }
}
