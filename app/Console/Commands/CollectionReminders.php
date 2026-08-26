<?php

namespace App\Console\Commands;

use App\Domain\Finance\Models\InvoiceExchange;
use App\Domain\Finance\Services\ExchangeSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The collections day list (F-50).
 *
 * Two questions every morning: whose invoices go out today, and who promised
 * to pay by now and has not. Both are the difference between a distributor
 * that collects on schedule and one that finds out at month end.
 */
final class CollectionReminders extends Command
{
    protected $signature = 'collections:today {--on= : The date to run for, defaults to today}';

    protected $description = 'Customers due an invoice exchange today, and promised payments now overdue';

    public function handle(ExchangeSchedule $schedule): int
    {
        $on = $this->option('on') ? CarbonImmutable::parse($this->option('on')) : CarbonImmutable::now();

        $due = $schedule->dueOn($on);

        $this->info(sprintf('Invoice exchange, %s', $on->format('l j F Y')));

        if ($due->isEmpty()) {
            $this->line('  No customers are scheduled today.');
        } else {
            $this->table(['Code', 'Customer', 'Exchange days'], $due->map(fn ($customer): array => [
                $customer->code,
                $customer->name,
                implode(', ', (array) $customer->invoice_exchange_days),
            ])->all());
        }

        $overdue = InvoiceExchange::query()
            ->with('customer')
            ->where('status', 'handed_over')
            ->whereNotNull('promised_payment_date')
            ->whereDate('promised_payment_date', '<', $on->format('Y-m-d'))
            ->orderBy('promised_payment_date')
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No promised payments are overdue.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d promised payment(s) overdue:', $overdue->count()));
        $this->table(['Exchange', 'Customer', 'Promised', 'Days late', 'Amount'], $overdue->map(fn ($exchange): array => [
            $exchange->document_number,
            $exchange->customer->name,
            $exchange->promised_payment_date->format('Y-m-d'),
            (int) $exchange->promised_payment_date->startOfDay()->diffInDays($on->startOfDay()),
            (string) $exchange->total_amount,
        ])->all());

        return self::SUCCESS;
    }
}
