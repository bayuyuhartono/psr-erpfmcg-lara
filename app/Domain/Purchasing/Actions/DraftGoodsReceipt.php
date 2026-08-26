<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Inventory\Actions\ResolveStockBatch;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Product;
use App\Domain\Purchasing\Exceptions\InvalidGoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Support\Posting\DocumentNumberer;
use App\Support\Uom\UomConverter;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Records what arrived, before any of it becomes stock (F-10).
 *
 * Batches are resolved here rather than at posting, which is what makes the
 * shelf-life rule a rejection at the gate: `ResolveStockBatch` refuses goods
 * whose remaining life is below the product's threshold, so a receipt for them
 * cannot even be written down as accepted. Turning them away is recorded
 * instead — a quantity rejected, with the reason — which is what the claim
 * against the principal is built from.
 *
 * One PO line may arrive as several batches, so a receipt line points at a PO
 * line rather than replacing it.
 */
final class DraftGoodsReceipt
{
    public function __construct(
        private readonly UomConverter $uom,
        private readonly ResolveStockBatch $resolveBatch,
        private readonly DocumentNumberer $numberer,
    ) {}

    /**
     * @param  list<array{
     *     product: Product, uom?: string, qty: string, unit_cost?: string,
     *     batch_number?: string, production_date?: DateTimeInterface, expiry_date?: DateTimeInterface,
     *     location_id?: string, purchase_order_line_id?: string,
     *     qty_rejected?: string, rejection_reason?: string
     * }>  $lines
     */
    public function __invoke(
        BusinessPartner $supplier,
        string $warehouseId,
        array $lines,
        ?PurchaseOrder $order = null,
        ?DateTimeInterface $receiptDate = null,
        ?string $supplierDoNumber = null,
        ?BusinessPartner $owner = null,
    ): GoodsReceipt {
        if (! $supplier->is_supplier) {
            throw new InvalidGoodsReceipt(sprintf('%s is not a supplier.', $supplier->name));
        }

        if ($lines === []) {
            throw new InvalidGoodsReceipt('A goods receipt needs at least one line.');
        }

        if ($order !== null && ! in_array($order->status, ['approved', 'partially_received'], true)) {
            throw new InvalidGoodsReceipt(sprintf(
                'Purchase order %s is %s. Goods can only be received against an approved order.',
                $order->document_number,
                $order->status,
            ));
        }

        $receiptDate = CarbonImmutable::instance($receiptDate ?? CarbonImmutable::now())->startOfDay();

        return DB::transaction(function () use ($supplier, $warehouseId, $lines, $order, $receiptDate, $supplierDoNumber, $owner): GoodsReceipt {
            $receipt = GoodsReceipt::create([
                'document_number' => $this->numberer->next('GR', $receiptDate),
                'purchase_order_id' => $order?->getKey(),
                'supplier_id' => $supplier->getKey(),
                'warehouse_id' => $warehouseId,
                'owner_id' => $owner?->getKey(),
                'receipt_date' => $receiptDate->format('Y-m-d'),
                'supplier_do_number' => $supplierDoNumber,
            ]);

            foreach ($lines as $line) {
                $this->addLine($receipt, $line, $receiptDate);
            }

            return $receipt->refresh();
        });
    }

    /**
     * What arrived against what was ordered (F-10).
     *
     * Two differences need explaining and one does not. Delivering **more**
     * than is outstanding commits money nobody approved, so it always needs a
     * reason. Declaring a line **finished short** closes an order the supplier
     * will never complete, so that needs one too. A plain partial delivery
     * needs nothing — the rest is simply still coming, which is what
     * `partially_received` means.
     *
     * @param  array<string, mixed>  $line
     */
    private function varianceReason(Product $product, array $line, BigDecimal $receivedBase, BigDecimal $rejected): ?string
    {
        $orderLineId = $line['purchase_order_line_id'] ?? null;

        if ($orderLineId === null) {
            return null;
        }

        /** @var PurchaseOrderLine $orderLine */
        $orderLine = PurchaseOrderLine::findOrFail($orderLineId);
        $outstanding = $orderLine->qty_base->minus($orderLine->qty_received);
        $closingShort = (bool) ($line['close_line'] ?? false);
        $reason = $line['variance_reason'] ?? null;

        if ($receivedBase->isGreaterThan($outstanding)) {
            if ($reason === null) {
                throw new InvalidGoodsReceipt(sprintf(
                    '%s of %s arrived against %s still on order. Over-delivery needs a reason.',
                    $receivedBase->stripTrailingZeros(),
                    $product->sku,
                    $outstanding->stripTrailingZeros(),
                ));
            }

            return $reason;
        }

        if ($closingShort && $receivedBase->plus($rejected)->isLessThan($outstanding)) {
            if ($reason === null) {
                throw new InvalidGoodsReceipt(sprintf(
                    'Closing %s short at %s of %s needs a reason.',
                    $product->sku,
                    $receivedBase->stripTrailingZeros(),
                    $outstanding->stripTrailingZeros(),
                ));
            }

            $orderLine->update(['closed_short' => true]);

            return $reason;
        }

        return $reason;
    }

    /** @param  array<string, mixed>  $line */
    private function addLine(GoodsReceipt $receipt, array $line, CarbonImmutable $receivedOn): void
    {
        /** @var Product $product */
        $product = $line['product'];
        $unit = $line['uom'] ?? $product->base_uom;
        $snapshot = $this->uom->snapshot($product, $unit, $line['qty'] ?? '0');
        $rejected = BigDecimal::of($line['qty_rejected'] ?? '0')->toScale(4, RoundingMode::HALF_UP);
        $reason = $line['rejection_reason'] ?? null;

        if ($snapshot->quantity->isNegative() || $rejected->isNegative()) {
            throw new InvalidGoodsReceipt(sprintf('Quantities for %s cannot be negative.', $product->sku));
        }

        if ($snapshot->quantity->isZero() && $rejected->isZero()) {
            throw new InvalidGoodsReceipt(sprintf('Nothing was recorded for %s — neither accepted nor rejected.', $product->sku));
        }

        // Turning goods away is a claim against the supplier, so it needs a
        // reason recorded at the moment it happens rather than reconstructed.
        if ($rejected->isPositive() && $reason === null) {
            throw new InvalidGoodsReceipt(sprintf('Rejecting %s of %s needs a reason.', $rejected->stripTrailingZeros(), $product->sku));
        }

        $accepted = $snapshot->quantity->isPositive();

        if ($accepted && ($line['unit_cost'] ?? null) === null) {
            throw new InvalidGoodsReceipt(sprintf('Accepted goods need a unit cost: %s.', $product->sku));
        }

        $varianceReason = $this->varianceReason($product, $line, $snapshot->quantityBase, $rejected);

        // Only goods being accepted are checked against the shelf-life rule —
        // the whole point of rejecting a batch is that it never becomes stock.
        $batch = $accepted
            ? ($this->resolveBatch)(
                $product,
                $line['batch_number'] ?? null,
                $receivedOn,
                $line['production_date'] ?? null,
                $line['expiry_date'] ?? null,
            )
            : null;

        GoodsReceiptLine::create($snapshot->toLineAttributes() + [
            'goods_receipt_id' => $receipt->getKey(),
            'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
            'product_id' => $product->getKey(),
            'batch_id' => $batch?->getKey(),
            'location_id' => $line['location_id'] ?? null,
            'qty_rejected' => $rejected,
            'rejection_reason' => $reason,
            'variance_reason' => $varianceReason,
            'unit_cost' => $line['unit_cost'] ?? '0',
        ]);
    }
}
