<?php

namespace App\Domain\Sales\Services;

use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Sales\Models\SalesInvoice;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on the dashboard, computed in SQL.
 *
 * A widget that hydrates models to add them up is the one that stops loading
 * when a client has a year of invoices, so every figure here is an aggregate
 * the database performs. They come back as strings and become BigDecimal —
 * `SUM()` over `decimal(18,2)` in PHP is a float, and a float is how a balance
 * sheet ends up off by cents.
 */
final class DashboardFigures
{
    /** Invoices that still owe money. Cancelled ones never did. */
    private const OWING = ['posted', 'exchanged', 'partially_paid'];

    public function salesThisMonth(): BigDecimal
    {
        return $this->money(
            SalesInvoice::query()
                ->whereNot('status', 'cancelled')
                ->whereBetween('invoice_date', [
                    CarbonImmutable::now()->startOfMonth()->toDateString(),
                    CarbonImmutable::now()->endOfMonth()->toDateString(),
                ])
                ->sum('total'),
        );
    }

    public function salesLastMonth(): BigDecimal
    {
        $month = CarbonImmutable::now()->subMonthNoOverflow();

        return $this->money(
            SalesInvoice::query()
                ->whereNot('status', 'cancelled')
                ->whereBetween('invoice_date', [
                    $month->startOfMonth()->toDateString(),
                    $month->endOfMonth()->toDateString(),
                ])
                ->sum('total'),
        );
    }

    /** What customers owe us: invoiced less paid, not invoiced total. */
    public function receivable(): BigDecimal
    {
        return $this->money(
            SalesInvoice::query()
                ->whereIn('status', self::OWING)
                ->sum(DB::raw('total - paid_amount')),
        );
    }

    public function overdue(): BigDecimal
    {
        return $this->money($this->overdueQuery()->sum(DB::raw('total - paid_amount')));
    }

    public function overdueCount(): int
    {
        return $this->overdueQuery()->count();
    }

    /**
     * Owned stock only.
     *
     * Consigned-in goods sit in our warehouse and belong to the principal.
     * Counting them here overstates what the business is worth by their full
     * value (schema §11) — the reason `owner_id IS NULL` is not optional.
     */
    public function stockValue(): BigDecimal
    {
        return $this->money(
            StockBalance::query()->withoutGlobalScopes()->whereNull('owner_id')->sum('value'),
        );
    }

    /**
     * Invoiced value per day, oldest first.
     *
     * Days with no invoices are filled in as zero: a chart that simply omits
     * them draws a quiet Sunday as a straight line to Monday and hides it.
     *
     * @return array<string, BigDecimal>
     */
    public function dailySales(int $days = 14): array
    {
        $from = CarbonImmutable::today()->subDays($days - 1);

        $rows = SalesInvoice::query()
            ->whereNot('status', 'cancelled')
            ->where('invoice_date', '>=', $from->toDateString())
            ->groupBy('invoice_date')
            ->pluck(DB::raw('SUM(total)'), 'invoice_date');

        $series = [];
        for ($day = $from; $day <= CarbonImmutable::today(); $day = $day->addDay()) {
            $key = $day->toDateString();
            $series[$key] = $this->money($rows[$key] ?? 0);
        }

        return $series;
    }

    private function overdueQuery(): Builder
    {
        return SalesInvoice::query()
            ->whereIn('status', self::OWING)
            // `due_date` is NOT NULL, so there is no null case to guard.
            ->whereDate('due_date', '<', CarbonImmutable::today()->toDateString());
    }

    private function money(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? '0'))->toScale(2);
    }
}
