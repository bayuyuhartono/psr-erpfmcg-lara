<?php

namespace App\Support\Posting;

use App\Domain\Inventory\Models\StockBalance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recomputes `stock_balances` from `stock_ledger` (F-21).
 *
 * The cache exists for speed and nothing else — the ledger is the truth. If the
 * two disagree, the ledger is right and the cache is rebuilt from it. Being
 * able to prove that on demand is what makes an unexplained stock variance
 * investigable rather than a matter of trust.
 *
 * Every query here drops the OwnedStock global scope. Consigned stock is real
 * stock in a real warehouse; a rebuild that filtered it out would report every
 * consigned balance as unbacked and then delete it.
 */
final class BalanceRebuilder
{
    private const QUANTITY_SCALE = 4;

    private const MONEY_SCALE = 2;

    /**
     * Read-only. Safe to run against a live system, and worth running on a
     * schedule — a discrepancy here means something wrote stock without going
     * through the kernel.
     *
     * @return list<BalanceDiscrepancy>
     */
    public function check(?string $productId = null, ?string $warehouseId = null): array
    {
        return $this->compare($this->ledgerTotals($productId, $warehouseId), $this->cached($productId, $warehouseId));
    }

    /**
     * Corrects the cache and returns what it corrected.
     *
     * Locks the cached rows before reading the ledger, the same order a posting
     * takes them in, so the two cannot deadlock. A movement that creates a
     * balance row this rebuild never saw is still a race: run it when posting
     * is quiet, and run `check()` afterwards.
     *
     * @return list<BalanceDiscrepancy>
     */
    public function rebuild(?string $productId = null, ?string $warehouseId = null): array
    {
        return DB::transaction(function () use ($productId, $warehouseId): array {
            $cached = $this->cached($productId, $warehouseId, locked: true);
            $ledger = $this->ledgerTotals($productId, $warehouseId, locked: true);
            $differences = $this->compare($ledger, $cached);

            foreach ($differences as $difference) {
                $key = $this->key($difference->warehouseId, $difference->productId, $difference->batchId, $difference->ownerId);

                if ($difference->ledgerQuantity === null) {
                    StockBalance::withoutGlobalScopes()->whereKey($cached[$key]->id)->delete();

                    continue;
                }

                StockBalance::withoutGlobalScopes()->updateOrCreate(
                    [
                        'warehouse_id' => $difference->warehouseId,
                        'product_id' => $difference->productId,
                        'batch_id' => $difference->batchId,
                        'owner_id' => $difference->ownerId,
                    ],
                    [
                        'id' => $cached[$key]->id ?? (string) Str::uuid7(),
                        'qty_base' => $difference->ledgerQuantity,
                        'value' => $difference->ledgerValue,
                    ],
                );
            }

            return $differences;
        });
    }

    /**
     * @param  Collection<string, object>  $ledger
     * @param  Collection<string, StockBalance>  $cached
     * @return list<BalanceDiscrepancy>
     */
    private function compare(Collection $ledger, Collection $cached): array
    {
        $differences = [];

        foreach ($ledger as $key => $totals) {
            $balance = $cached->get($key);
            $ledgerQuantity = $this->quantity($totals->qty_base);
            $ledgerValue = $this->money($totals->value);

            if ($balance
                && $this->quantity($balance->qty_base)->isEqualTo($ledgerQuantity)
                && $this->money($balance->value)->isEqualTo($ledgerValue)) {
                continue;
            }

            $differences[] = new BalanceDiscrepancy(
                $totals->warehouse_id,
                $totals->product_id,
                $totals->batch_id,
                $totals->owner_id,
                $balance ? $this->quantity($balance->qty_base) : null,
                $ledgerQuantity,
                $balance ? $this->money($balance->value) : null,
                $ledgerValue,
            );
        }

        foreach ($cached as $key => $balance) {
            if ($ledger->has($key)) {
                continue;
            }

            $differences[] = new BalanceDiscrepancy(
                $balance->warehouse_id,
                $balance->product_id,
                $balance->batch_id,
                $balance->owner_id,
                $this->quantity($balance->qty_base),
                null,
                $this->money($balance->value),
                null,
            );
        }

        return $differences;
    }

    /** @return Collection<string, object> */
    private function ledgerTotals(?string $productId, ?string $warehouseId, bool $locked = false): Collection
    {
        return $this->scope(DB::table('stock_ledger'), $productId, $warehouseId)
            ->selectRaw('warehouse_id, product_id, batch_id, owner_id, SUM(qty_base) as qty_base, SUM(value) as value')
            ->groupBy('warehouse_id', 'product_id', 'batch_id', 'owner_id')
            ->when($locked, fn (Builder $query) => $query->sharedLock())
            ->get()
            ->keyBy(fn (object $row): string => $this->key($row->warehouse_id, $row->product_id, $row->batch_id, $row->owner_id));
    }

    /** @return Collection<string, StockBalance> */
    private function cached(?string $productId, ?string $warehouseId, bool $locked = false): Collection
    {
        return StockBalance::withoutGlobalScopes()
            ->when($productId, fn ($query, $id) => $query->where('product_id', $id))
            ->when($warehouseId, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->when($locked, fn ($query) => $query->lockForUpdate())
            ->get()
            ->keyBy(fn (StockBalance $balance): string => $this->key($balance->warehouse_id, $balance->product_id, $balance->batch_id, $balance->owner_id));
    }

    private function scope(Builder $query, ?string $productId, ?string $warehouseId): Builder
    {
        return $query
            ->when($productId, fn (Builder $query, string $id) => $query->where('product_id', $id))
            ->when($warehouseId, fn (Builder $query, string $id) => $query->where('warehouse_id', $id));
    }

    private function key(string $warehouseId, string $productId, ?string $batchId, ?string $ownerId): string
    {
        return implode('|', [$warehouseId, $productId, $batchId ?? '-', $ownerId ?? '-']);
    }

    private function quantity(BigDecimal|string|int $value): BigDecimal
    {
        return BigDecimal::of($value)->toScale(self::QUANTITY_SCALE, RoundingMode::HALF_UP);
    }

    private function money(BigDecimal|string|int $value): BigDecimal
    {
        return BigDecimal::of($value)->toScale(self::MONEY_SCALE, RoundingMode::HALF_UP);
    }
}
