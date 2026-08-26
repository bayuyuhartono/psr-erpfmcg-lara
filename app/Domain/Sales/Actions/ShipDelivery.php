<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Sales\Exceptions\InvalidDelivery;
use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\DeliveryLine;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Models\SalesOrderLine;
use App\Models\User;
use App\Support\Posting\PostingTransaction;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The load leaves: stock goes out, cost of goods is recognised.
 *
 *   DR 5101 Cost of Goods Sold     CR 1103 Inventory
 *
 * Revenue is not recognised here — that waits for the invoice, which waits for
 * proof the outlet actually received the goods (F-42). Booking a sale when the
 * truck leaves counts loads that come back as revenue.
 *
 * Reservations are consumed rather than left to expire: the goods are gone, so
 * holding them against the order as well would double-count.
 */
final class ShipDelivery
{
    public function __construct(private readonly StockPosting $posting) {}

    public function __invoke(Delivery $delivery, ?User $actor = null): Delivery
    {
        $lines = $delivery->deliveryLines()->get();

        if ($lines->isEmpty()) {
            throw new InvalidDelivery(sprintf('Delivery %s has nothing on it.', $delivery->document_number));
        }

        return PostingTransaction::run(function () use ($delivery, $lines, $actor): Delivery {
            // A deadlock retry re-runs this closure, and $delivery still
            // carries whatever the rolled-back attempt put on it.
            $delivery->refresh();

            if ($delivery->status === 'picking') {
                $delivery->transitionTo('loaded', $actor);
            }

            $delivery->transitionTo('in_transit', $actor);

            $this->posting->postDocument($lines->map(fn (DeliveryLine $line): StockMovement => new StockMovement(
                warehouseId: $delivery->warehouse_id,
                productId: $line->product_id,
                quantity: $line->qty_base->negated(),
                unitCost: null,
                referenceType: 'delivery',
                referenceId: $delivery->getKey(),
                transactionDate: $delivery->delivery_date,
                referenceLineId: $line->getKey(),
                locationId: $line->location_id,
                batchId: $line->batch_id,
                actorId: $actor?->getKey(),
                partnerId: $delivery->customer_id,
                description: sprintf('Delivery %s', $delivery->document_number),
            ))->values()->all());

            $this->recordCostOnLines($delivery);
            $this->advanceOrders($delivery, $lines);

            return $delivery->refresh();
        });
    }

    /** The cost the goods actually left at, kept for the margin report. */
    private function recordCostOnLines(Delivery $delivery): void
    {
        $costs = DB::table('stock_ledger')
            ->where('ref_type', 'delivery')
            ->where('ref_id', $delivery->getKey())
            ->pluck('unit_cost', 'ref_line_id');

        foreach ($costs as $lineId => $cost) {
            DeliveryLine::whereKey($lineId)->update(['unit_cost' => $cost]);
        }
    }

    /** @param  Collection<int, DeliveryLine>  $lines */
    private function advanceOrders(Delivery $delivery, $lines): void
    {
        foreach ($lines as $line) {
            if ($line->sales_order_line_id !== null) {
                SalesOrderLine::whereKey($line->sales_order_line_id)->increment('qty_shipped', (string) $line->qty_base);
            }
        }

        foreach ($delivery->salesOrders()->get() as $order) {
            /** @var SalesOrder $order */
            StockReservation::query()
                ->where('ref_type', 'sales_order')
                ->where('ref_id', $order->getKey())
                ->where('status', 'active')
                ->get()
                ->each(fn (StockReservation $reservation) => $reservation->update(['status' => 'consumed']));

            $order->load('salesOrderLines');
            $next = $order->salesOrderLines->every(
                fn (SalesOrderLine $line): bool => $line->qty_shipped->isGreaterThanOrEqualTo($line->qty_base),
            ) ? 'closed' : 'partially_shipped';

            if ($order->status !== $next && $order->canTransitionTo($next)) {
                $order->transitionTo($next);
            }
        }
    }
}
