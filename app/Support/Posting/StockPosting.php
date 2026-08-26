<?php

namespace App\Support\Posting;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockLedger;
use App\Support\Posting\Exceptions\InsufficientStock;
use App\Support\Posting\Exceptions\InvalidStockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StockPosting
{
    private const QUANTITY_SCALE = 4;

    private const COST_SCALE = 4;

    private const MONEY_SCALE = 2;

    public function __construct(
        private readonly JournalPoster $journals,
        private readonly StockJournalRules $rules,
        private readonly PostingGuard $guard,
        private readonly PeriodGuard $periods,
    ) {}

    /**
     * Post every movement a document makes, once.
     *
     * This is what modules call. `post()` below is the single-movement
     * primitive underneath it, and using it directly for a document skips the
     * claim that stops a double submission posting twice.
     *
     * @param  list<StockMovement>  $movements  all from one document
     * @return list<StockPostingResult>
     */
    public function postDocument(array $movements): array
    {
        if ($movements === []) {
            throw new InvalidStockMovement('A document must post at least one stock movement.');
        }

        $first = $movements[array_key_first($movements)];

        foreach ($movements as $movement) {
            if ($movement->referenceType !== $first->referenceType || $movement->referenceId !== $first->referenceId) {
                throw new InvalidStockMovement('All movements in one posting must belong to the same document.');
            }
        }

        return DB::transaction(function () use ($movements, $first): array {
            $this->guard->claim($first->referenceType, $first->referenceId, $first->actorId);

            return array_map(fn (StockMovement $movement): StockPostingResult => $this->post($movement), $movements);
        });
    }

    public function post(StockMovement $movement): StockPostingResult
    {
        $this->validate($movement);
        $this->periods->assertOpen($movement->transactionDate, sprintf('This %s movement', $movement->referenceType));

        return DB::transaction(function () use ($movement): StockPostingResult {
            $cost = $this->lockProductCost($movement->productId);
            [$unitCost, $value] = $this->costMovement($movement, $cost);

            $balance = $this->lockBalance($movement);
            $newBalanceQuantity = $this->quantity($balance?->qty_base ?? 0)->plus($movement->quantity);
            if ($newBalanceQuantity->isNegative()) {
                throw new InsufficientStock('Stock movement would make the warehouse/batch/owner balance negative.');
            }

            $ledger = StockLedger::withoutGlobalScopes()->create([
                'warehouse_id' => $movement->warehouseId,
                'location_id' => $movement->locationId,
                'product_id' => $movement->productId,
                'batch_id' => $movement->batchId,
                'owner_id' => $movement->ownerId,
                'qty_base' => $this->quantity($movement->quantity),
                'unit_cost' => $unitCost,
                'value' => $value,
                'ref_type' => $movement->referenceType,
                'ref_id' => $movement->referenceId,
                'ref_line_id' => $movement->referenceLineId,
                'reverses_ledger_id' => $movement->reversesLedgerId,
                'transaction_date' => $movement->transactionDate->format('Y-m-d'),
                'created_by' => $movement->actorId,
            ]);

            $balance = $this->writeBalance($movement, $balance, $newBalanceQuantity, $value);
            $cost = $this->writeProductCost($movement, $cost, $value);
            $journal = $this->writeJournal($movement, $value);

            return new StockPostingResult($ledger, $balance, $cost, $journal);
        }, 3);
    }

    private function validate(StockMovement $movement): void
    {
        if ($movement->quantity->isZero()) {
            throw new InvalidStockMovement('Stock movement quantity cannot be zero.');
        }

        if (($movement->quantity->isPositive() || $movement->ownerId !== null) && $movement->unitCost === null) {
            throw new InvalidStockMovement('Unit cost is required for inbound and consigned stock movements.');
        }

        if ($movement->unitCost?->isNegative()) {
            throw new InvalidStockMovement('Unit cost cannot be negative.');
        }

        if ($movement->reversesLedgerId !== null && $movement->unitCost === null) {
            throw new InvalidStockMovement('A reversal must carry the unit cost of the row it cancels.');
        }

        if ($movement->referenceType === '' || $movement->referenceId === '') {
            throw new InvalidStockMovement('A source document type and ID are required.');
        }
    }

    private function lockProductCost(string $productId): ProductCost
    {
        DB::table('product_costs')->insertOrIgnore([
            'product_id' => $productId,
            'average_cost' => '0.0000',
            'total_qty' => '0.0000',
            'total_value' => '0.00',
            'updated_at' => now(),
        ]);

        return ProductCost::query()->where('product_id', $productId)->lockForUpdate()->firstOrFail();
    }

    private function lockBalance(StockMovement $movement): ?StockBalance
    {
        return StockBalance::withoutGlobalScopes()
            ->where('warehouse_id', $movement->warehouseId)
            ->where('product_id', $movement->productId)
            ->when($movement->batchId, fn ($query, $batchId) => $query->where('batch_id', $batchId), fn ($query) => $query->whereNull('batch_id'))
            ->when($movement->ownerId, fn ($query, $ownerId) => $query->where('owner_id', $ownerId), fn ($query) => $query->whereNull('owner_id'))
            ->lockForUpdate()
            ->first();
    }

    /** @return array{BigDecimal, BigDecimal} */
    private function costMovement(StockMovement $movement, ProductCost $cost): array
    {
        $unitCost = $movement->unitCost;
        if ($movement->quantity->isNegative() && $movement->ownerId === null && $movement->reversesLedgerId === null) {
            $unitCost = $this->cost($cost->average_cost);
        }

        $unitCost ??= BigDecimal::zero();
        $unitCost = $this->cost($unitCost);
        $value = $this->money($movement->quantity->multipliedBy($unitCost));

        return [$unitCost, $value];
    }

    private function writeBalance(
        StockMovement $movement,
        ?StockBalance $balance,
        BigDecimal $newQuantity,
        BigDecimal $movementValue,
    ): StockBalance {
        $newValue = $this->money(($balance?->value ?? BigDecimal::zero())->plus($movementValue));

        if ($balance) {
            $balance->forceFill(['qty_base' => $this->quantity($newQuantity), 'value' => $newValue])->save();

            return $balance->refresh();
        }

        return StockBalance::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'warehouse_id' => $movement->warehouseId,
            'product_id' => $movement->productId,
            'batch_id' => $movement->batchId,
            'owner_id' => $movement->ownerId,
            'qty_base' => $this->quantity($newQuantity),
            'value' => $newValue,
        ]);
    }

    private function writeProductCost(StockMovement $movement, ProductCost $cost, BigDecimal $movementValue): ProductCost
    {
        if ($movement->ownerId !== null) {
            return $cost;
        }

        $totalQuantity = $this->quantity($cost->total_qty->plus($movement->quantity));
        if ($totalQuantity->isNegative()) {
            throw new InsufficientStock('Owned stock movement would make total product quantity negative.');
        }

        $totalValue = $this->money($cost->total_value->plus($movementValue));
        if ($totalQuantity->isZero()) {
            $totalValue = $this->money(0);
            $averageCost = $this->cost(0);
        } else {
            $averageCost = $this->cost($totalValue->dividedBy($totalQuantity, self::COST_SCALE, RoundingMode::HALF_UP));
        }

        ProductCost::query()->where('product_id', $movement->productId)->update([
            'average_cost' => (string) $averageCost,
            'total_qty' => (string) $totalQuantity,
            'total_value' => (string) $totalValue,
            'updated_at' => now(),
        ]);

        return ProductCost::query()->where('product_id', $movement->productId)->firstOrFail();
    }

    /**
     * The fourth step of a posting, inside the same transaction as the ledger,
     * balance, and cost writes: the inventory account moves by exactly the
     * value the ledger recorded.
     */
    private function writeJournal(StockMovement $movement, BigDecimal $movementValue): ?JournalEntry
    {
        // Consigned-in goods sit in our warehouse but belong to the principal.
        // They never touch our inventory account; the payable appears at
        // settlement instead (schema §11).
        if ($movement->ownerId !== null) {
            return null;
        }

        $counterpart = $this->rules->counterpartFor($movement->referenceType);
        $amount = $movementValue->abs();
        if ($counterpart === null || $amount->isZero()) {
            return null;
        }

        $inventory = StockJournalRules::INVENTORY_KEY;
        $inbound = $movement->quantity->isPositive();

        return $this->journals->post(new JournalDocument(
            documentType: $movement->referenceType,
            referenceId: $movement->referenceId,
            entryDate: $movement->transactionDate,
            description: $movement->description,
            postings: [
                JournalPosting::debit(
                    $inbound ? $inventory : $counterpart,
                    $amount,
                    partnerId: $inbound ? null : $movement->partnerId,
                    branchId: $movement->branchId,
                ),
                JournalPosting::credit(
                    $inbound ? $counterpart : $inventory,
                    $amount,
                    partnerId: $inbound ? $movement->partnerId : null,
                    branchId: $movement->branchId,
                ),
            ],
            actorId: $movement->actorId,
        ));
    }

    private function quantity(BigDecimal|string|int $value): BigDecimal
    {
        return BigDecimal::of($value)->toScale(self::QUANTITY_SCALE, RoundingMode::HALF_UP);
    }

    private function cost(BigDecimal|string|int $value): BigDecimal
    {
        return BigDecimal::of($value)->toScale(self::COST_SCALE, RoundingMode::HALF_UP);
    }

    private function money(BigDecimal|string|int $value): BigDecimal
    {
        return BigDecimal::of($value)->toScale(self::MONEY_SCALE, RoundingMode::HALF_UP);
    }
}
