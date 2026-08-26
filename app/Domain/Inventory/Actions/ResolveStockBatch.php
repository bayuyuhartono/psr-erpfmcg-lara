<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Masters\Models\Product;
use App\Support\ShelfLife\Exceptions\InvalidBatchData;
use App\Support\ShelfLife\ShelfLifePolicy;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Finds or creates the batch a receipt is landing into, filling in the expiry
 * and refusing goods that arrive too short-dated (F-03, F-10).
 *
 * Every inbound movement of a batch-tracked product comes through here, so the
 * shelf-life rejection cannot be skipped by receiving through a different
 * screen.
 */
final class ResolveStockBatch
{
    public function __construct(private readonly ShelfLifePolicy $shelfLife) {}

    public function __invoke(
        Product $product,
        ?string $batchNumber,
        DateTimeInterface $receivedOn,
        ?DateTimeInterface $productionDate = null,
        ?DateTimeInterface $expiryDate = null,
    ): ?StockBatch {
        if (! $product->track_batch) {
            if ($batchNumber !== null) {
                throw new InvalidBatchData(sprintf('Product %s does not track batches, so "%s" has nowhere to go.', $product->sku, $batchNumber));
            }

            return null;
        }

        if ($batchNumber === null || trim($batchNumber) === '') {
            throw new InvalidBatchData(sprintf('Product %s is batch-tracked, so the goods need a batch number.', $product->sku));
        }

        $batchNumber = trim($batchNumber);
        $expiry = $expiryDate === null
            ? $this->shelfLife->expiryFor($product, $productionDate)
            : CarbonImmutable::instance($expiryDate)->startOfDay();

        if ($product->track_expiry && $expiry === null) {
            throw new InvalidBatchData(sprintf(
                'Product %s is expiry-tracked, so batch %s needs an expiry date or a production date to compute one from.',
                $product->sku,
                $batchNumber,
            ));
        }

        $this->shelfLife->assertAcceptableOnReceipt($product, $expiry, $receivedOn);

        return DB::transaction(function () use ($product, $batchNumber, $productionDate, $expiry): StockBatch {
            $batch = StockBatch::where('product_id', $product->getKey())
                ->where('batch_number', $batchNumber)
                ->lockForUpdate()
                ->first();

            if (! $batch) {
                return StockBatch::create([
                    'product_id' => $product->getKey(),
                    'batch_number' => $batchNumber,
                    'production_date' => $productionDate === null ? null : CarbonImmutable::instance($productionDate)->startOfDay(),
                    'expiry_date' => $expiry,
                ]);
            }

            // A batch number identifies goods made in one run, so the same
            // number arriving with a different expiry means one of the two
            // was keyed wrong. Guessing which would corrupt FEFO.
            if ($expiry !== null && $batch->expiry_date !== null && ! $expiry->isSameDay($batch->expiry_date)) {
                throw new InvalidBatchData(sprintf(
                    'Batch %s of %s is already recorded as expiring %s, not %s. Correct whichever is wrong before receiving.',
                    $batchNumber,
                    $product->sku,
                    $batch->expiry_date->format('Y-m-d'),
                    $expiry->format('Y-m-d'),
                ));
            }

            if ($expiry !== null && $batch->expiry_date === null) {
                $batch->update(['expiry_date' => $expiry]);
            }

            return $batch;
        });
    }
}
