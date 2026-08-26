<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\InvalidStockTransfer;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Models\User;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use Illuminate\Support\Facades\DB;

/** Takes the goods out of the sending warehouse. */
final class ShipStockTransfer
{
    public function __construct(private readonly StockPosting $posting) {}

    public function __invoke(StockTransfer $transfer, ?User $actor = null): StockTransfer
    {
        if ($transfer->from_warehouse_id === $transfer->to_warehouse_id) {
            throw new InvalidStockTransfer('A transfer needs two different warehouses.');
        }

        $lines = $transfer->stockTransferLines()->get();

        if ($lines->isEmpty()) {
            throw new InvalidStockTransfer(sprintf('Transfer %s has no lines.', $transfer->document_number));
        }

        return DB::transaction(function () use ($transfer, $lines, $actor): StockTransfer {
            $transfer->transitionTo('shipped', $actor);

            $this->posting->postDocument($lines->map(fn (StockTransferLine $line): StockMovement => new StockMovement(
                warehouseId: $transfer->from_warehouse_id,
                productId: $line->product_id,
                quantity: $line->qty_base->negated(),
                unitCost: null,
                referenceType: 'transfer_out',
                referenceId: $transfer->getKey(),
                transactionDate: $transfer->transfer_date,
                referenceLineId: $line->getKey(),
                batchId: $line->batch_id,
                actorId: $actor?->getKey(),
                description: sprintf('Transfer %s out', $transfer->document_number),
            ))->values()->all());

            return $transfer->refresh();
        });
    }
}
