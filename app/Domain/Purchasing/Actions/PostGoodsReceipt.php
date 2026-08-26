<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Exceptions\InvalidGoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Models\User;
use App\Support\Posting\PostingTransaction;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use Illuminate\Support\Collection;

/**
 * Turns a checked receipt into stock.
 *
 * Everything the receipt accepted goes through `postDocument()` as one
 * document: ledger, balances, moving average and the GR/IR journal, in a
 * single transaction, claimed once so a double click cannot receive twice.
 * The purchase order's received quantities and status follow from it.
 */
final class PostGoodsReceipt
{
    public function __construct(private readonly StockPosting $posting) {}

    public function __invoke(GoodsReceipt $receipt, ?User $actor = null): GoodsReceipt
    {
        $lines = $receipt->goodsReceiptLines()->with('product')->get();

        $accepted = $lines->filter(fn (GoodsReceiptLine $line): bool => $line->qty_base->isPositive());

        if ($accepted->isEmpty()) {
            throw new InvalidGoodsReceipt(sprintf(
                'Goods receipt %s accepted nothing, so there is no stock to post. Cancel it instead.',
                $receipt->document_number,
            ));
        }

        return PostingTransaction::run(function () use ($receipt, $accepted, $actor): GoodsReceipt {
            // A deadlock retry re-runs this closure, and $receipt still
            // carries whatever the rolled-back attempt put on it.
            $receipt->refresh();

            // The state machine first, the posting claim underneath it. The
            // transition is the readable guard for the ordinary second click;
            // the claim is the one that holds when two requests are in flight
            // at once and neither has seen the other's transition yet.
            $receipt->transitionTo('posted', $actor);

            $this->posting->postDocument($accepted->map(fn (GoodsReceiptLine $line): StockMovement => new StockMovement(
                warehouseId: $receipt->warehouse_id,
                productId: $line->product_id,
                quantity: $line->qty_base,
                unitCost: $line->unit_cost,
                referenceType: 'goods_receipt',
                referenceId: $receipt->getKey(),
                transactionDate: $receipt->receipt_date,
                referenceLineId: $line->getKey(),
                locationId: $line->location_id,
                batchId: $line->batch_id,
                ownerId: $receipt->owner_id,
                actorId: $actor?->getKey(),
                partnerId: $receipt->supplier_id,
                description: sprintf('Goods receipt %s', $receipt->document_number),
            ))->values()->all());

            $this->advanceOrder($receipt, $accepted);

            return $receipt->refresh();
        });
    }

    /** @param  Collection<int, GoodsReceiptLine>  $accepted */
    private function advanceOrder(GoodsReceipt $receipt, $accepted): void
    {
        $order = $receipt->purchaseOrder;

        if (! $order instanceof PurchaseOrder) {
            return;
        }

        foreach ($accepted as $line) {
            if ($line->purchase_order_line_id === null) {
                continue;
            }

            PurchaseOrderLine::whereKey($line->purchase_order_line_id)
                ->increment('qty_received', (string) $line->qty_base);
        }

        $order->load('purchaseOrderLines');

        // Closed once nothing is outstanding. Until then it is partially
        // received, which is what keeps it available for the next delivery.
        $next = $order->isFullyReceived() ? 'closed' : 'partially_received';

        if ($order->status !== $next && $order->canTransitionTo($next)) {
            $order->transitionTo($next);
        }
    }
}
