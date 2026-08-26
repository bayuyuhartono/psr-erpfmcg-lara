<?php

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\DeliveryLine;

/**
 * A delivery's lines in the order the racks are walked (F-40).
 *
 * Sorted by `pick_sequence`, not by product or by order. A picker working
 * alphabetically crosses the warehouse and comes back for every line; the
 * sequence exists so they walk it once.
 */
final class PickingList
{
    /** @return list<array{location: string, sku: string, product: string, batch: string, expiry: ?string, qty: string, note: ?string}> */
    public function for(Delivery $delivery): array
    {
        // Ordered in SQL. A multi-key sort over a collection is easy to write
        // and easy to get silently wrong, and this is the list someone walks.
        return $delivery->deliveryLines()
            ->with(['product', 'batch', 'location'])
            ->leftJoin('warehouse_locations', 'warehouse_locations.id', '=', 'delivery_lines.location_id')
            ->leftJoin('products', 'products.id', '=', 'delivery_lines.product_id')
            ->orderByRaw('warehouse_locations.pick_sequence IS NULL')
            ->orderBy('warehouse_locations.pick_sequence')
            ->orderBy('warehouse_locations.code')
            ->orderBy('products.sku')
            ->select('delivery_lines.*')
            ->get()
            ->map(fn (DeliveryLine $line): array => [
                'location' => $line->location?->code ?? 'unassigned',
                'sku' => $line->product->sku,
                'product' => $line->product->name,
                'batch' => $line->batch?->batch_number ?? '—',
                'expiry' => $line->batch?->expiry_date?->format('Y-m-d'),
                'qty' => (string) $line->qty_base,
                'note' => $line->batch_override_reason,
            ])
            ->values()
            ->all();
    }
}
