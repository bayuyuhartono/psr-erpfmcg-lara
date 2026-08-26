<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Inventory\Services\BatchPicker;
use App\Domain\Sales\Exceptions\InvalidDelivery;
use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\DeliveryLine;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Models\SalesOrderLine;
use App\Support\Posting\DocumentNumberer;
use App\Support\Posting\PostingTransaction;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Builds one delivery from one or more approved orders (F-41).
 *
 * One trip, one document. An outlet that ordered three times this week gets a
 * single delivery note rather than three, which is both what the driver wants
 * and what the outlet will sign for.
 *
 * Batches are chosen here, at picking, by nearest expiry — the point where the
 * physical goods are finally decided (F-20).
 */
final class PlanDelivery
{
    public function __construct(
        private readonly BatchPicker $picker,
        private readonly DocumentNumberer $numberer,
    ) {}

    /**
     * @param  Collection<int, SalesOrder>|list<SalesOrder>  $orders
     * @param  array<string, array{batch_id: string, reason: string}>  $overrides  keyed by order line ID
     */
    public function __invoke(
        $orders,
        ?DateTimeInterface $deliveryDate = null,
        ?string $driverId = null,
        ?string $vehiclePlate = null,
        array $overrides = [],
    ): Delivery {
        $orders = collect($orders);

        if ($orders->isEmpty()) {
            throw new InvalidDelivery('A delivery needs at least one order.');
        }

        if ($orders->pluck('customer_id')->unique()->count() > 1) {
            throw new InvalidDelivery('One delivery covers one customer. Different customers need different trips.');
        }

        if ($orders->pluck('warehouse_id')->unique()->count() > 1) {
            throw new InvalidDelivery('These orders ship from different warehouses.');
        }

        $unapproved = $orders->reject(fn (SalesOrder $order): bool => in_array($order->status, ['approved', 'partially_shipped'], true));

        if ($unapproved->isNotEmpty()) {
            throw new InvalidDelivery(sprintf(
                'Order %s is %s. Only approved orders are delivered.',
                $unapproved->first()->document_number,
                $unapproved->first()->status,
            ));
        }

        $deliveryDate = CarbonImmutable::instance($deliveryDate ?? CarbonImmutable::now())->startOfDay();
        $first = $orders->first();

        return PostingTransaction::run(function () use ($orders, $first, $deliveryDate, $driverId, $vehiclePlate, $overrides): Delivery {
            $delivery = Delivery::create([
                'document_number' => $this->numberer->next('DO', $deliveryDate),
                'customer_id' => $first->customer_id,
                'shipping_address_id' => $first->shipping_address_id,
                'warehouse_id' => $first->warehouse_id,
                'delivery_date' => $deliveryDate->format('Y-m-d'),
                'driver_id' => $driverId,
                'vehicle_plate' => $vehiclePlate,
            ]);

            $delivery->salesOrders()->attach($orders->pluck('id')->all());

            foreach ($orders as $order) {
                foreach ($order->salesOrderLines()->with('product')->get() as $line) {
                    $this->allocate($delivery, $line, $deliveryDate, $overrides[$line->getKey()] ?? null);
                }
            }

            return $delivery->transitionTo('picking');
        });
    }

    /** @param  array{batch_id: string, reason: string}|null  $override */
    private function allocate(Delivery $delivery, SalesOrderLine $line, CarbonImmutable $on, ?array $override): void
    {
        $outstanding = $line->qty_base->minus($line->qty_shipped);

        if (! $outstanding->isPositive()) {
            return;
        }

        $picks = $this->picker->pick(
            $line->product,
            $delivery->warehouse_id,
            $outstanding,
            $on,
            forceBatchId: $override['batch_id'] ?? null,
            overrideReason: $override['reason'] ?? null,
        );

        foreach ($picks as $pick) {
            DeliveryLine::create([
                'delivery_id' => $delivery->getKey(),
                'sales_order_line_id' => $line->getKey(),
                'product_id' => $line->product_id,
                'batch_id' => $pick->batch?->getKey(),
                'location_id' => null,
                'qty_base' => $pick->quantityBase,
                'batch_override_reason' => $pick->overrideReason,
            ]);
        }
    }
}
