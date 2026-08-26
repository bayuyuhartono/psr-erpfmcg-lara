<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\CompanySetting;
use App\Domain\Masters\Models\Product;
use App\Domain\Sales\Exceptions\InvalidSalesOrder;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Models\SalesOrderLine;
use App\Support\Posting\DocumentNumberer;
use App\Support\Posting\PostingTransaction;
use App\Support\Pricing\PriceResolver;
use App\Support\Uom\UomConverter;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Raises a sales order, priced from the customer's tier (F-32).
 *
 * Lines snapshot everything that could later move: the unit and its factor,
 * the price, and each discount tier separately — because a customer asking why
 * an invoice says what it says needs to see 5% then 3%, not 8.35%.
 *
 * Bonus goods leave stock at zero revenue but still carry cost, so they are
 * ordinary lines with `is_bonus` set rather than a free-text note. The cost
 * follows them out of the warehouse either way; pretending otherwise
 * overstates margin by exactly the value given away.
 */
final class DraftSalesOrder
{
    public function __construct(
        private readonly UomConverter $uom,
        private readonly PriceResolver $prices,
        private readonly DocumentNumberer $numberer,
    ) {}

    /**
     * @param  list<array{product: Product, uom?: string, qty: string, is_bonus?: bool, promotion_id?: string}>  $lines
     */
    public function __invoke(
        BusinessPartner $customer,
        string $warehouseId,
        array $lines,
        ?DateTimeInterface $orderDate = null,
        ?string $salespersonId = null,
        ?string $shippingAddressId = null,
        string $source = 'office',
    ): SalesOrder {
        if (! $customer->is_customer) {
            throw new InvalidSalesOrder(sprintf('%s is not a customer.', $customer->name));
        }

        if ($lines === []) {
            throw new InvalidSalesOrder('A sales order needs at least one line.');
        }

        $orderDate = CarbonImmutable::instance($orderDate ?? CarbonImmutable::now())->startOfDay();

        return PostingTransaction::run(function () use ($customer, $warehouseId, $lines, $orderDate, $salespersonId, $shippingAddressId, $source): SalesOrder {
            $order = SalesOrder::create([
                'document_number' => $this->numberer->next('SO', $orderDate),
                'customer_id' => $customer->getKey(),
                'warehouse_id' => $warehouseId,
                'salesperson_id' => $salespersonId ?? $customer->salesperson_id,
                'shipping_address_id' => $shippingAddressId,
                'order_date' => $orderDate->format('Y-m-d'),
                'price_list_id' => $customer->tier?->price_list_id,
                'source' => $source,
            ]);

            foreach ($lines as $line) {
                $this->addLine($order, $customer, $line, $orderDate);
            }

            return $this->retotal($order->refresh());
        });
    }

    /** @param  array<string, mixed>  $line */
    private function addLine(SalesOrder $order, BusinessPartner $customer, array $line, CarbonImmutable $orderDate): void
    {
        /** @var Product $product */
        $product = $line['product'];
        $unit = $line['uom'] ?? $this->defaultSaleUnit($product);
        $snapshot = $this->uom->snapshot($product, $unit, $line['qty']);
        $isBonus = (bool) ($line['is_bonus'] ?? false);

        if (! $snapshot->quantity->isPositive()) {
            throw new InvalidSalesOrder(sprintf('Ordered quantity for %s must be more than zero.', $product->sku));
        }

        $attributes = $snapshot->toLineAttributes() + [
            'sales_order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'is_bonus' => $isBonus,
            'promotion_id' => $line['promotion_id'] ?? null,
        ];

        if ($isBonus) {
            SalesOrderLine::create($attributes + [
                'unit_price' => '0.00', 'discount_amount' => '0.00', 'line_total' => '0.00',
            ]);

            return;
        }

        $quote = $this->prices->quote($product, $unit, $snapshot->quantity, $customer, $orderDate);

        SalesOrderLine::create($attributes + $quote->toLineAttributes());
    }

    /** Recomputes the header from its lines. Nothing else may write these. */
    public function retotal(SalesOrder $order): SalesOrder
    {
        $defaultVat = CompanySetting::query()->value('default_vat_percent') ?? '11';
        $subtotal = BigDecimal::zero();
        $discount = BigDecimal::zero();
        $vat = BigDecimal::zero();

        foreach ($order->salesOrderLines()->with('product')->get() as $line) {
            $gross = $line->qty->multipliedBy($line->unit_price);
            $subtotal = $subtotal->plus($gross);
            $discount = $discount->plus($gross->minus($line->line_total));
            $rate = BigDecimal::of($line->product->vat_percent ?? $defaultVat);
            $vat = $vat->plus($line->line_total->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP));
        }

        $order->forceFill([
            'subtotal' => $subtotal->toScale(2, RoundingMode::HALF_UP),
            'discount_amount' => $discount->toScale(2, RoundingMode::HALF_UP),
            'vat_amount' => $vat->toScale(2, RoundingMode::HALF_UP),
            'total' => $subtotal->minus($discount)->plus($vat)->toScale(2, RoundingMode::HALF_UP),
        ])->save();

        return $order->refresh();
    }

    private function defaultSaleUnit(Product $product): string
    {
        return $product->productUoms->firstWhere('default_for_sale', true)?->uom ?? $product->base_uom;
    }
}
