<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockCountLine;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\Category;
use App\Support\Posting\DocumentNumberer;
use App\Support\Posting\PostingTransaction;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Opens a count and freezes what the system thinks is there (F-22).
 *
 * The freeze is what lets the warehouse keep operating. `system_qty` is copied
 * onto each line now; goods received or shipped during the count move the
 * balance without moving the number being counted against, so the variance at
 * the end is a real discrepancy rather than a day's trading.
 */
final class OpenStockCount
{
    public function __construct(private readonly DocumentNumberer $numberer) {}

    public function __invoke(
        Warehouse $warehouse,
        ?Category $category = null,
        ?DateTimeInterface $countDate = null,
        string $countType = 'full',
    ): StockCount {
        $countDate = CarbonImmutable::instance($countDate ?? CarbonImmutable::now())->startOfDay();

        return PostingTransaction::run(function () use ($warehouse, $category, $countDate, $countType): StockCount {
            $count = StockCount::create([
                'document_number' => $this->numberer->next('SC', $countDate),
                'warehouse_id' => $warehouse->getKey(),
                'count_date' => $countDate->format('Y-m-d'),
                'count_type' => $countType,
                'scope_category_id' => $category?->getKey(),
            ]);

            $balances = StockBalance::query()
                ->where('warehouse_id', $warehouse->getKey())
                ->when($category, fn ($query, Category $scope) => $query->whereHas(
                    'product',
                    fn ($products) => $products->where('category_id', $scope->getKey()),
                ))
                ->get();

            foreach ($balances as $balance) {
                StockCountLine::create([
                    'count_id' => $count->getKey(),
                    'product_id' => $balance->product_id,
                    'batch_id' => $balance->batch_id,
                    'system_qty' => $balance->qty_base,
                ]);
            }

            return $count->transitionTo('counting');
        });
    }
}
