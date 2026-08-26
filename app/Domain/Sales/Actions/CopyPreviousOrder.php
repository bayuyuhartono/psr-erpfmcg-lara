<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Product;
use App\Domain\Sales\Exceptions\InvalidSalesOrder;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Models\SalesOrderLine;
use DateTimeInterface;

/**
 * Starts a new order from the customer's last one (F-31).
 *
 * Outlets reorder much the same list every week, so this is most of the two
 * minutes. Quantities and units carry over; **prices do not** — they are
 * re-quoted at today's price list, because copying last month's price forward
 * is how an old promotion quietly becomes permanent.
 */
final class CopyPreviousOrder
{
    public function __construct(private readonly DraftSalesOrder $draft) {}

    public function __invoke(
        BusinessPartner $customer,
        string $warehouseId,
        ?SalesOrder $source = null,
        ?DateTimeInterface $orderDate = null,
    ): SalesOrder {
        $source ??= $this->lastOrderFor($customer);

        return ($this->draft)($customer, $warehouseId, $this->linesFor($customer, $source), $orderDate);
    }

    /**
     * The lines alone, without raising an order.
     *
     * Order entry loads these into a working list the admin can edit before
     * committing — reorders are rarely identical. Creating an order only to
     * throw it away would burn a document number out of the sequence for a
     * button someone pressed by mistake.
     *
     * @return list<array{product: Product, uom: string, qty: string, is_bonus: bool}>
     */
    public function linesFor(BusinessPartner $customer, ?SalesOrder $source = null): array
    {
        $source ??= $this->lastOrderFor($customer);

        if ($source === null) {
            throw new InvalidSalesOrder(sprintf('%s has no previous order to copy.', $customer->name));
        }

        $lines = $source->salesOrderLines()->with('product')->get()
            ->map(fn (SalesOrderLine $line): array => [
                'product' => $line->product,
                'uom' => $line->uom,
                'qty' => (string) $line->qty,
                'is_bonus' => (bool) $line->is_bonus,
            ])
            ->values()
            ->all();

        if ($lines === []) {
            throw new InvalidSalesOrder(sprintf('Order %s has no lines to copy.', $source->document_number));
        }

        return $lines;
    }

    private function lastOrderFor(BusinessPartner $customer): ?SalesOrder
    {
        return SalesOrder::query()
            ->where('customer_id', $customer->getKey())
            ->whereNot('status', 'cancelled')
            ->latest('order_date')
            ->latest('created_at')
            ->first();
    }
}
