<?php

namespace App\Filament\Widgets;

use App\Domain\Sales\Services\DashboardFigures;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/**
 * The four numbers an owner opens the system to see.
 *
 * Stock value is behind the same ability that hides cost on the product screen
 * (F-70): what the goods cost us is the one figure a distributor keeps off the
 * sales floor, and a dashboard is the easiest place to leak it.
 */
class BusinessOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $figures = app(DashboardFigures::class);

        $thisMonth = $figures->salesThisMonth();
        $lastMonth = $figures->salesLastMonth();
        $overdueCount = $figures->overdueCount();

        $stats = [
            Stat::make(__('Sales this month'), $this->rupiah($thisMonth))
                ->description($this->comparison($thisMonth, $lastMonth))
                ->descriptionIcon($thisMonth->isGreaterThanOrEqualTo($lastMonth)
                    ? 'heroicon-m-arrow-trending-up'
                    : 'heroicon-m-arrow-trending-down')
                ->color($thisMonth->isGreaterThanOrEqualTo($lastMonth) ? 'success' : 'danger'),

            Stat::make(__('Owed by customers'), $this->rupiah($figures->receivable()))
                ->description(__('Invoiced and not yet paid'))
                ->color('info'),

            Stat::make(__('Overdue'), $this->rupiah($figures->overdue()))
                // Not trans_choice: an inline pluralisation string is not a
                // key, so it never reaches the translation file. Indonesian
                // does not inflect the noun for number in any case.
                ->description($overdueCount === 0
                    ? __('Nothing past due')
                    : __(':count invoices past due', ['count' => $overdueCount]))
                ->color($overdueCount > 0 ? 'danger' : 'success'),
        ];

        if (Gate::allows('view-cost')) {
            $stats[] = Stat::make(__('Stock value'), $this->rupiah($figures->stockValue()))
                ->description(__('Our own goods, at moving average cost'))
                ->color('gray');
        }

        return $stats;
    }

    /**
     * Indonesian convention, and abbreviated: a stat card is not wide enough
     * for 1.234.567.890,00 and truncating a number is worse than rounding it.
     */
    private function rupiah(BigDecimal $amount): string
    {
        $value = (float) (string) $amount;

        return match (true) {
            abs($value) >= 1_000_000_000 => 'Rp '.number_format($value / 1_000_000_000, 2, ',', '.').' M',
            abs($value) >= 1_000_000 => 'Rp '.number_format($value / 1_000_000, 1, ',', '.').' jt',
            default => 'Rp '.number_format($value, 0, ',', '.'),
        };
    }

    private function comparison(BigDecimal $thisMonth, BigDecimal $lastMonth): string
    {
        if ($lastMonth->isZero()) {
            return __('No invoices last month to compare with');
        }

        $change = $thisMonth->minus($lastMonth)
            ->dividedBy($lastMonth, 4, RoundingMode::HALF_UP)
            ->multipliedBy(100);

        return __(':percent% against last month', [
            'percent' => number_format((float) (string) $change, 1, ',', '.'),
        ]);
    }
}
