<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Models\SalesOrderLine;
use App\Domain\Sales\Services\CreditCheck;
use App\Models\User;
use App\Support\Posting\PostingTransaction;
use Carbon\CarbonImmutable;

/**
 * Submits an order, holding it if the customer's credit says so (F-30, F-33).
 *
 * The check runs on save, automatically, with no way to skip it — a hold that
 * depended on someone remembering to look would not be a control. What it
 * produces is a state, and the numbers behind it are on the order for whoever
 * has to decide.
 *
 * An order that clears reserves its stock immediately, so the same goods
 * cannot be promised to two customers between now and picking.
 */
final class SubmitSalesOrder
{
    /** How long unprocessed orders may hold stock before the cleanup frees it. */
    private const RESERVATION_DAYS = 7;

    public function __construct(private readonly CreditCheck $credit) {}

    public function __invoke(SalesOrder $order, ?User $actor = null): SalesOrder
    {
        $status = ($this->credit)($order->customer, $order->order_date);
        $holdReason = $status->holdReasonFor($order->total);

        return PostingTransaction::run(function () use ($order, $actor, $status, $holdReason): SalesOrder {
            $order->refresh();

            if ($holdReason !== null) {
                return $order->transitionTo('credit_hold', $actor, [
                    'hold_reason' => $holdReason,
                    'hold_detail' => $status->explain($order->total),
                ]);
            }

            $order->transitionTo('approved', $actor);
            $this->reserve($order);

            return $order->refresh();
        });
    }

    public function reserve(SalesOrder $order): void
    {
        foreach ($order->salesOrderLines()->get() as $line) {
            /** @var SalesOrderLine $line */
            StockReservation::create([
                'warehouse_id' => $order->warehouse_id,
                'product_id' => $line->product_id,
                'qty_base' => $line->qty_base,
                'ref_type' => 'sales_order',
                'ref_id' => $order->getKey(),
                // Expiry is not optional: an order left unprocessed would
                // otherwise hold its goods for ever (schema §11).
                'expires_at' => CarbonImmutable::now()->addDays(self::RESERVATION_DAYS),
            ]);
        }
    }
}
