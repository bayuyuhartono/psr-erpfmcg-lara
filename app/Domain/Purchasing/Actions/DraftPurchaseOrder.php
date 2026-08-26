<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\CompanySetting;
use App\Domain\Masters\Models\Product;
use App\Domain\Purchasing\Exceptions\InvalidPurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Support\Posting\DocumentNumberer;
use App\Support\Posting\PostingTransaction;
use App\Support\Uom\UomConverter;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Raises a purchase order in draft.
 *
 * Every line takes a snapshot: the unit, its conversion factor, the price and
 * the discount are copied onto the line and never read back from the master.
 * Correcting a carton size next month must not restate what was ordered today.
 */
final class DraftPurchaseOrder
{
    public function __construct(
        private readonly UomConverter $uom,
        private readonly DocumentNumberer $numberer,
    ) {}

    /**
     * @param  list<array{product: Product, uom?: string, qty: string, unit_price: string, discount_percent?: string}>  $lines
     */
    public function __invoke(
        BusinessPartner $supplier,
        string $warehouseId,
        array $lines,
        ?DateTimeInterface $orderDate = null,
        ?DateTimeInterface $expectedDate = null,
        ?string $note = null,
        ?string $branchId = null,
    ): PurchaseOrder {
        if (! $supplier->is_supplier) {
            throw new InvalidPurchaseOrder(sprintf('%s is not a supplier.', $supplier->name));
        }

        if ($lines === []) {
            throw new InvalidPurchaseOrder('A purchase order needs at least one line.');
        }

        $orderDate = CarbonImmutable::instance($orderDate ?? CarbonImmutable::now())->startOfDay();

        return PostingTransaction::run(function () use ($supplier, $warehouseId, $lines, $orderDate, $expectedDate, $note, $branchId): PurchaseOrder {
            $order = PurchaseOrder::create([
                'document_number' => $this->numberer->next('PO', $orderDate),
                'branch_id' => $branchId,
                'supplier_id' => $supplier->getKey(),
                'warehouse_id' => $warehouseId,
                'order_date' => $orderDate->format('Y-m-d'),
                'expected_date' => $expectedDate?->format('Y-m-d'),
                'payment_terms_days' => $supplier->payment_terms_days,
                'note' => $note,
            ]);

            foreach ($lines as $line) {
                $this->addLine($order, $line);
            }

            return $this->retotal($order->refresh());
        });
    }

    /** @param  array{product: Product, uom?: string, qty: string, unit_price: string, discount_percent?: string}  $line */
    private function addLine(PurchaseOrder $order, array $line): void
    {
        /** @var Product $product */
        $product = $line['product'];
        $unit = $line['uom'] ?? $this->defaultPurchaseUnit($product);
        $snapshot = $this->uom->snapshot($product, $unit, $line['qty']);

        if (! $snapshot->quantity->isPositive()) {
            throw new InvalidPurchaseOrder(sprintf('Ordered quantity for %s must be more than zero.', $product->sku));
        }

        $unitPrice = BigDecimal::of($line['unit_price'])->toScale(2, RoundingMode::HALF_UP);

        if ($unitPrice->isNegative()) {
            throw new InvalidPurchaseOrder(sprintf('Unit price for %s cannot be negative.', $product->sku));
        }

        $discountPercent = BigDecimal::of($line['discount_percent'] ?? '0')->toScale(2, RoundingMode::HALF_UP);
        $gross = $snapshot->quantity->multipliedBy($unitPrice);
        $discount = $gross->multipliedBy($discountPercent)->dividedBy(100, 2, RoundingMode::HALF_UP);

        PurchaseOrderLine::create($snapshot->toLineAttributes() + [
            'purchase_order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'unit_price' => $unitPrice,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discount,
            'line_total' => $gross->minus($discount)->toScale(2, RoundingMode::HALF_UP),
        ]);
    }

    /** Recomputes the header from its lines. Nothing else may write these. */
    public function retotal(PurchaseOrder $order): PurchaseOrder
    {
        $defaultVat = CompanySetting::query()->value('default_vat_percent') ?? '11';
        $subtotal = BigDecimal::zero();
        $discount = BigDecimal::zero();
        $vat = BigDecimal::zero();

        foreach ($order->purchaseOrderLines()->with('product')->get() as $line) {
            $subtotal = $subtotal->plus($line->qty->multipliedBy($line->unit_price));
            $discount = $discount->plus($line->discount_amount);
            $rate = BigDecimal::of($line->product->vat_percent ?? $defaultVat);
            $vat = $vat->plus($line->line_total->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP));
        }

        $net = $subtotal->minus($discount);

        $order->forceFill([
            'subtotal' => $subtotal->toScale(2, RoundingMode::HALF_UP),
            'discount_amount' => $discount->toScale(2, RoundingMode::HALF_UP),
            'vat_amount' => $vat->toScale(2, RoundingMode::HALF_UP),
            'total' => $net->plus($vat)->toScale(2, RoundingMode::HALF_UP),
        ])->save();

        return $order->refresh();
    }

    private function defaultPurchaseUnit(Product $product): string
    {
        return $product->productUoms->firstWhere('default_for_purchase', true)?->uom
            ?? $product->base_uom;
    }
}
