<?php

namespace App\Filament\Widgets;

use App\Domain\Sales\Services\DashboardFigures;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/**
 * Invoiced value per day for the last fortnight.
 *
 * Fourteen days rather than thirty: a distributor's week has a shape — heavy
 * on delivery days, flat on Sunday — and two of them is enough to see whether
 * this week is behind the last without squeezing a month into a thumbnail.
 */
class SalesTrend extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return __('Invoiced, last 14 days');
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $series = app(DashboardFigures::class)->dailySales();

        return [
            'datasets' => [[
                'label' => __('Invoiced'),
                // Millions: the axis is unreadable at full rupiah, and the
                // tooltip below says which unit this is.
                'data' => array_map(
                    fn ($amount): float => round((float) (string) $amount / 1_000_000, 2),
                    array_values($series),
                ),
                'borderColor' => 'rgba(79, 70, 229, 1)',
                'backgroundColor' => 'rgba(79, 70, 229, 0.1)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => array_map(
                fn (string $date): string => CarbonImmutable::parse($date)->format('d/m'),
                array_keys($series),
            ),
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'title' => ['display' => true, 'text' => __('Million rupiah')],
                ],
            ],
        ];
    }
}
