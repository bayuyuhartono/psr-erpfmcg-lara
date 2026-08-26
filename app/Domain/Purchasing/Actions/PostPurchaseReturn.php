<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Exceptions\InvalidPurchaseReturn;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\PurchaseReturnLine;
use App\Models\User;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use Illuminate\Support\Facades\DB;

/**
 * Sends goods back to the supplier and reduces what we owe them.
 *
 *   DR 2101 Trade Payables      CR 1103 Inventory
 *
 * The stock leaves at the moving average, because that is what it is carried
 * at — `unit_price` on the line is what we are claiming back, which is a
 * commercial figure and not necessarily the same number. They agree whenever
 * nothing has moved the average since the goods arrived, which is the ordinary
 * case for a return.
 */
final class PostPurchaseReturn
{
    public function __construct(private readonly StockPosting $posting) {}

    public function __invoke(PurchaseReturn $return, ?User $actor = null): PurchaseReturn
    {
        $lines = $return->purchaseReturnLines()->get();

        if ($lines->isEmpty()) {
            throw new InvalidPurchaseReturn(sprintf('Purchase return %s has no lines.', $return->document_number));
        }

        return DB::transaction(function () use ($return, $lines, $actor): PurchaseReturn {
            $return->transitionTo('posted', $actor);

            $this->posting->postDocument($lines->map(fn (PurchaseReturnLine $line): StockMovement => new StockMovement(
                warehouseId: $return->warehouse_id,
                productId: $line->product_id,
                quantity: $line->qty_base->negated(),
                unitCost: null,
                referenceType: 'purchase_return',
                referenceId: $return->getKey(),
                transactionDate: $return->return_date,
                referenceLineId: $line->getKey(),
                batchId: $line->batch_id,
                actorId: $actor?->getKey(),
                partnerId: $return->supplier_id,
                description: sprintf('Purchase return %s', $return->document_number),
            ))->values()->all());

            return $return->refresh();
        });
    }
}
