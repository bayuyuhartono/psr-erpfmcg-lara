<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\InvalidStockTransfer;
use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Models\User;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use Illuminate\Support\Facades\DB;

/**
 * Puts the goods into the receiving warehouse, at the cost they left at.
 *
 * That last part matters. `product_costs` is one figure per product across
 * every warehouse, so moving goods between them must not change it. Reading
 * the cost back off the shipping ledger rows keeps the two legs equal and
 * opposite; taking today's average instead would revalue the stock every time
 * it was moved, which is how a warehouse transfer quietly becomes a profit.
 */
final class ReceiveStockTransfer
{
    public function __construct(private readonly StockPosting $posting) {}

    public function __invoke(StockTransfer $transfer, ?User $actor = null): StockTransfer
    {
        $lines = $transfer->stockTransferLines()->get();
        $shippedCosts = StockLedger::withoutGlobalScopes()
            ->where('ref_type', 'transfer_out')
            ->where('ref_id', $transfer->getKey())
            ->pluck('unit_cost', 'ref_line_id');

        if ($shippedCosts->isEmpty()) {
            throw new InvalidStockTransfer(sprintf('Transfer %s has not been shipped yet.', $transfer->document_number));
        }

        return DB::transaction(function () use ($transfer, $lines, $shippedCosts, $actor): StockTransfer {
            $transfer->transitionTo('received', $actor);

            $this->posting->postDocument($lines->map(fn (StockTransferLine $line): StockMovement => new StockMovement(
                warehouseId: $transfer->to_warehouse_id,
                productId: $line->product_id,
                quantity: $line->qty_base,
                unitCost: (string) $shippedCosts[$line->getKey()],
                referenceType: 'transfer_in',
                referenceId: $transfer->getKey(),
                transactionDate: $transfer->transfer_date,
                referenceLineId: $line->getKey(),
                batchId: $line->batch_id,
                actorId: $actor?->getKey(),
                description: sprintf('Transfer %s in', $transfer->document_number),
            ))->values()->all());

            foreach ($lines as $line) {
                StockTransferLine::whereKey($line->getKey())->update(['qty_received' => (string) $line->qty_base]);
            }

            return $transfer->refresh();
        });
    }
}
