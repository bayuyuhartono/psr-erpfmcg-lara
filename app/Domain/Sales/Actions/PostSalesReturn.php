<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Sales\Exceptions\InvalidSalesReturn;
use App\Domain\Sales\Models\SalesReturn;
use App\Domain\Sales\Models\SalesReturnLine;
use App\Models\User;
use App\Support\Posting\PostingTransaction;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;

/**
 * Takes goods back from an outlet.
 *
 *   DR 1103 Inventory      CR 5101 Cost of Goods Sold
 *
 * Where they land is the decision that matters. Resellable stock goes back to
 * a normal warehouse and is available again; anything else goes to the damaged
 * warehouse, where it is still on the books and still countable but will never
 * be picked. Putting unsellable goods back on the shelf is how a distributor
 * delivers the same damaged carton twice.
 */
final class PostSalesReturn
{
    public function __construct(private readonly StockPosting $posting) {}

    public function __invoke(SalesReturn $return, ?User $actor = null): SalesReturn
    {
        $lines = $return->salesReturnLines()->with('product')->get();

        if ($lines->isEmpty()) {
            throw new InvalidSalesReturn(sprintf('Sales return %s has no lines.', $return->document_number));
        }

        $warehouse = Warehouse::findOrFail($return->warehouse_id);

        if (! $return->resellable && $warehouse->warehouse_type !== 'damaged') {
            throw new InvalidSalesReturn(sprintf(
                'These goods are not resellable, so they go to a damaged warehouse — %s is %s.',
                $warehouse->code,
                $warehouse->warehouse_type,
            ));
        }

        if ($return->resellable && $warehouse->warehouse_type === 'damaged') {
            throw new InvalidSalesReturn(sprintf(
                'Resellable goods do not go to the damaged warehouse: nothing there is ever picked.',
            ));
        }

        return PostingTransaction::run(function () use ($return, $lines, $actor): SalesReturn {
            // A deadlock retry re-runs this closure, and $return still
            // carries whatever the rolled-back attempt put on it.
            $return->refresh();

            $return->transitionTo('posted', $actor);

            $this->posting->postDocument($lines->map(fn (SalesReturnLine $line): StockMovement => new StockMovement(
                warehouseId: $return->warehouse_id,
                productId: $line->product_id,
                quantity: $line->qty_base,
                // Back in at what the rest of it is carried at. What the goods
                // originally cost is unknowable once the average has moved,
                // and this is the only figure that keeps the balance honest.
                unitCost: (string) ($line->product->cost?->average_cost ?? '0'),
                referenceType: 'sales_return',
                referenceId: $return->getKey(),
                transactionDate: $return->return_date,
                referenceLineId: $line->getKey(),
                batchId: $line->batch_id,
                actorId: $actor?->getKey(),
                partnerId: $return->customer_id,
                description: sprintf('Sales return %s (%s)', $return->document_number, $return->reason),
            ))->values()->all());

            return $return->refresh();
        });
    }
}
