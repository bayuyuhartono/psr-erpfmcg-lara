<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Models\StockReservation;
use Illuminate\Console\Command;

/**
 * Frees stock held by orders nobody processed (schema §11).
 *
 * A reservation with no expiry is a slow leak: an order abandoned in draft
 * holds its goods for ever, and the warehouse gradually cannot sell things it
 * physically has. Runs hourly.
 */
final class ReleaseExpiredReservations extends Command
{
    protected $signature = 'stock:release-expired-reservations {--check : Report what would be released without releasing it}';

    protected $description = 'Release stock reservations whose orders were never processed';

    public function handle(): int
    {
        $expired = StockReservation::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', now());

        $count = $expired->count();

        if ($count === 0) {
            $this->info('No reservations have expired.');

            return self::SUCCESS;
        }

        if ($this->option('check')) {
            $this->warn("{$count} reservation(s) have expired and are still holding stock.");

            return self::SUCCESS;
        }

        $expired->get()->each(fn (StockReservation $reservation) => $reservation->update(['status' => 'released']));

        $this->info("Released {$count} expired reservation(s).");

        return self::SUCCESS;
    }
}
