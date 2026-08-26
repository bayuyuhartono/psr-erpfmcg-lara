<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\NoPickableStock;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Masters\Models\Product;
use App\Support\ShelfLife\ShelfLifePolicy;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Chooses which batches leave the warehouse (F-20).
 *
 * The batch is decided **here, at picking** — not at order entry. An order
 * reserves a quantity; which physical goods satisfy it is a warehouse decision
 * made when someone walks the racks, which is why `batch_id` lives on
 * `delivery_lines` and not on `sales_order_lines`.
 *
 * Two rules apply before anything is offered. Nearest expiry goes first, so
 * older stock leaves before it ages out. And a batch below the product's
 * `min_life_on_ship_days` is never offered at all — an outlet that receives
 * short-dated goods sends them back, usually after the window to claim against
 * the principal has closed.
 */
final class BatchPicker
{
    public function __construct(private readonly ShelfLifePolicy $shelfLife) {}

    /**
     * @return list<PickAllocation>
     */
    public function pick(
        Product $product,
        string $warehouseId,
        BigDecimal|string|int $quantityBase,
        ?DateTimeInterface $on = null,
        ?string $forceBatchId = null,
        ?string $overrideReason = null,
    ): array {
        $needed = BigDecimal::of($quantityBase)->toScale(4, RoundingMode::HALF_UP);
        $on = CarbonImmutable::instance($on ?? CarbonImmutable::now())->startOfDay();

        if (! $needed->isPositive()) {
            throw new NoPickableStock('Nothing was asked for.');
        }

        if (! $product->track_batch) {
            return [new PickAllocation(null, $this->assertEnough($product, $this->untrackedAvailable($product, $warehouseId), $needed, false))];
        }

        $available = $this->shippableBatches($product, $warehouseId, $on);

        if ($forceBatchId !== null) {
            return [$this->override($product, $warehouseId, $forceBatchId, $needed, $overrideReason, $on)];
        }

        $allocations = [];
        $outstanding = $needed;

        foreach ($available as $balance) {
            if (! $outstanding->isPositive()) {
                break;
            }

            $take = $balance->qty_base->isLessThan($outstanding) ? $balance->qty_base : $outstanding;
            $allocations[] = new PickAllocation($balance->batch, $take->toScale(4, RoundingMode::HALF_UP));
            $outstanding = $outstanding->minus($take);
        }

        if ($outstanding->isPositive()) {
            throw new NoPickableStock(sprintf(
                'Only %s of %s can be picked: %s short. Stock below the shipping shelf-life threshold is not offered.',
                $needed->minus($outstanding)->stripTrailingZeros(),
                $product->sku,
                $outstanding->stripTrailingZeros(),
            ));
        }

        return $allocations;
    }

    /**
     * Batches with stock on hand that may still be shipped, nearest expiry
     * first. Batches with no expiry sort last — they cannot age out, so there
     * is no reason to clear them ahead of something that can.
     *
     * @return Collection<int, StockBalance>
     */
    private function shippableBatches(Product $product, string $warehouseId, CarbonImmutable $on): Collection
    {
        $order = $product->issue_method === 'fifo' ? 'stock_batches.created_at' : 'stock_batches.expiry_date';

        return StockBalance::query()
            ->with('batch')
            ->where('stock_balances.warehouse_id', $warehouseId)
            ->where('stock_balances.product_id', $product->getKey())
            ->where('stock_balances.qty_base', '>', 0)
            ->whereNotNull('stock_balances.batch_id')
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_balances.batch_id')
            ->when(
                $product->issue_method !== 'any',
                fn ($query) => $query->orderByRaw("{$order} IS NULL")->orderBy($order),
            )
            ->select('stock_balances.*')
            ->get()
            ->filter(fn (StockBalance $balance): bool => $this->shelfLife->isShippable($product, $balance->batch?->expiry_date, $on))
            ->values();
    }

    private function override(
        Product $product,
        string $warehouseId,
        string $batchId,
        BigDecimal $needed,
        ?string $reason,
        CarbonImmutable $on,
    ): PickAllocation {
        // Staff may take a batch out of order — a customer asking for a
        // specific production run, goods already on the trolley — but the
        // reason is recorded, because overriding FEFO is how stock ages out
        // unnoticed.
        if ($reason === null) {
            throw new NoPickableStock(sprintf('Picking a batch of %s out of order needs a reason.', $product->sku));
        }

        $batch = StockBatch::findOrFail($batchId);

        // The shelf-life filter is not an ordering preference, so an override
        // does not lift it.
        if (! $this->shelfLife->isShippable($product, $batch->expiry_date, $on)) {
            throw new NoPickableStock(sprintf(
                'Batch %s of %s is too close to expiry to ship, whatever the reason for choosing it.',
                $batch->batch_number,
                $product->sku,
            ));
        }

        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $product->getKey())
            ->where('batch_id', $batchId)
            ->first();

        return new PickAllocation($batch, $this->assertEnough($product, $balance?->qty_base, $needed, true), $reason);
    }

    private function untrackedAvailable(Product $product, string $warehouseId): ?BigDecimal
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $product->getKey())
            ->whereNull('batch_id')
            ->value('qty_base');
    }

    private function assertEnough(Product $product, ?BigDecimal $available, BigDecimal $needed, bool $isBatch): BigDecimal
    {
        $available ??= BigDecimal::zero();

        if ($available->isLessThan($needed)) {
            throw new NoPickableStock(sprintf(
                '%s of %s available%s, %s asked for.',
                $available->toScale(4)->stripTrailingZeros(),
                $product->sku,
                $isBatch ? ' in that batch' : '',
                $needed->stripTrailingZeros(),
            ));
        }

        return $needed;
    }
}
