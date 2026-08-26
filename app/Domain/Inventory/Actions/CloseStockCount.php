<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\InvalidStockCount;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAdjustmentLine;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockCountLine;
use App\Models\User;
use App\Support\Posting\DocumentNumberer;
use App\Support\Posting\PostingTransaction;

/**
 * Turns a finished count into an adjustment waiting for approval (F-22).
 *
 * The adjustment is raised in draft on purpose. A variance is a question —
 * where did these go — and posting it unapproved turns a discrepancy into a
 * fact nobody had to explain. Lines that agree produce nothing at all.
 */
final class CloseStockCount
{
    public function __construct(private readonly DocumentNumberer $numberer) {}

    public function __invoke(StockCount $count, ?User $actor = null): ?StockAdjustment
    {
        $lines = $count->stockCountLines()->get();

        if ($lines->contains(fn (StockCountLine $line): bool => $line->counted_qty === null)) {
            throw new InvalidStockCount(sprintf(
                'Count %s still has lines nobody has counted. Record a figure for every line, including the empty shelves.',
                $count->document_number,
            ));
        }

        return PostingTransaction::run(function () use ($count, $lines, $actor): ?StockAdjustment {
            $count->refresh();

            $count->transitionTo('reviewed', $actor);

            $variances = $lines->filter(fn (StockCountLine $line): bool => ! $line->variance->isZero());

            if ($variances->isEmpty()) {
                $count->transitionTo('posted', $actor);

                return null;
            }

            $adjustment = StockAdjustment::create([
                'document_number' => $this->numberer->next('ADJ', $count->count_date),
                'warehouse_id' => $count->warehouse_id,
                'adjustment_date' => $count->count_date->format('Y-m-d'),
                'reason' => 'count_variance',
                'count_id' => $count->getKey(),
            ]);

            foreach ($variances as $line) {
                StockAdjustmentLine::create([
                    'adjustment_id' => $adjustment->getKey(),
                    'product_id' => $line->product_id,
                    'batch_id' => $line->batch_id,
                    'qty_base' => $line->variance,
                ]);
            }

            return $adjustment->refresh();
        });
    }
}
