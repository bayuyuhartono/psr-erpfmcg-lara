<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\InvalidStockAdjustment;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAdjustmentLine;
use App\Models\User;
use App\Support\Posting\PostingTransaction;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;

/**
 * Writes an approved adjustment into stock.
 *
 *   found:   DR 1103 Inventory        CR 5102 Inventory Variance
 *   missing: DR 5102 Inventory Variance   CR 1103 Inventory
 *
 * Stock found is valued at the moving average, which is the only defensible
 * number available: nobody knows what it cost, only what the rest of it is
 * carried at.
 */
final class PostStockAdjustment
{
    public function __construct(private readonly StockPosting $posting) {}

    public function __invoke(StockAdjustment $adjustment, ?User $actor = null): StockAdjustment
    {
        if ($adjustment->status !== 'approved') {
            throw new InvalidStockAdjustment(sprintf(
                'Adjustment %s is %s. Stock only moves once someone has approved the variance.',
                $adjustment->document_number,
                $adjustment->status,
            ));
        }

        $lines = $adjustment->stockAdjustmentLines()->with('product')->get();

        if ($lines->isEmpty()) {
            throw new InvalidStockAdjustment(sprintf('Adjustment %s has no lines.', $adjustment->document_number));
        }

        return PostingTransaction::run(function () use ($adjustment, $lines, $actor): StockAdjustment {
            // A deadlock retry re-runs this closure, and $adjustment still
            // carries whatever the rolled-back attempt put on it.
            $adjustment->refresh();

            $adjustment->transitionTo('posted', $actor);

            $this->posting->postDocument($lines->map(fn (StockAdjustmentLine $line): StockMovement => new StockMovement(
                warehouseId: $adjustment->warehouse_id,
                productId: $line->product_id,
                quantity: $line->qty_base,
                // Stock found comes in at what the rest of it is carried at;
                // stock missing leaves at the same figure, which the kernel
                // supplies for any outbound movement.
                unitCost: $line->qty_base->isPositive() ? $this->carryingCost($line) : null,
                referenceType: 'adjustment',
                referenceId: $adjustment->getKey(),
                transactionDate: $adjustment->adjustment_date,
                referenceLineId: $line->getKey(),
                batchId: $line->batch_id,
                actorId: $actor?->getKey(),
                description: sprintf('Stock adjustment %s (%s)', $adjustment->document_number, $adjustment->reason),
            ))->values()->all());

            return $adjustment->refresh();
        });
    }

    private function carryingCost(StockAdjustmentLine $line): string
    {
        return (string) ($line->product->cost?->average_cost ?? '0');
    }
}
