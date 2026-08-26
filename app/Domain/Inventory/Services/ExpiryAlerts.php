<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\StockBalance;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What is about to go out of date, and what it is worth (F-23).
 *
 * In rupiah, not just cartons. A warehouse manager reading "400 units expiring
 * within 30 days" has no way to know whether to push a promotion or let it go;
 * "Rp 18,400,000 expiring within 30 days" decides itself.
 *
 * Consigned-in goods are excluded by the `OwnedStock` scope, as everywhere
 * else: they are the principal's problem, and counting them here would send
 * someone chasing a write-off that was never ours.
 */
final class ExpiryAlerts
{
    /** Bands in days remaining. Anything past the last one is not reported. */
    private const BANDS = [
        ['Already expired', 0],
        ['Within 30 days', 30],
        ['Within 60 days', 60],
        ['Within 90 days', 90],
    ];

    /** @return list<ExpiryBand> */
    public function __invoke(?string $warehouseId = null, ?DateTimeInterface $on = null): array
    {
        $on = CarbonImmutable::instance($on ?? CarbonImmutable::now())->startOfDay();
        $horizon = $on->addDays(self::BANDS[count(self::BANDS) - 1][1]);

        $balances = StockBalance::query()
            ->with(['batch', 'product'])
            ->where('qty_base', '>', 0)
            ->whereNotNull('batch_id')
            ->when($warehouseId, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->whereHas('batch', fn ($query) => $query
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', $horizon->format('Y-m-d')))
            ->get();

        $bands = [];

        foreach (self::BANDS as [$label, $withinDays]) {
            $bands[$label] = ['within' => $withinDays, 'qty' => BigDecimal::zero(), 'value' => BigDecimal::zero(), 'items' => []];
        }

        foreach ($balances as $balance) {
            $remaining = $on->startOfDay()->diffInDays($balance->batch->expiry_date->startOfDay(), false);
            $label = $this->bandFor((int) $remaining);

            if ($label === null) {
                continue;
            }

            $bands[$label]['qty'] = $bands[$label]['qty']->plus($balance->qty_base);
            $bands[$label]['value'] = $bands[$label]['value']->plus($balance->value);
            $bands[$label]['items'][] = [
                'sku' => $balance->product->sku,
                'batch' => $balance->batch->batch_number,
                'expiry' => $balance->batch->expiry_date->format('Y-m-d'),
                'qty' => (string) $balance->qty_base,
                'value' => (string) $balance->value,
            ];
        }

        return array_values(array_map(
            fn (string $label): ExpiryBand => new ExpiryBand(
                $label,
                $bands[$label]['within'],
                $bands[$label]['qty']->toScale(4),
                $bands[$label]['value']->toScale(2),
                $bands[$label]['items'],
            ),
            array_keys($bands),
        ));
    }

    private function bandFor(int $remainingDays): ?string
    {
        if ($remainingDays <= 0) {
            return self::BANDS[0][0];
        }

        foreach (self::BANDS as [$label, $withinDays]) {
            if ($withinDays > 0 && $remainingDays <= $withinDays) {
                return $label;
            }
        }

        return null;
    }
}
