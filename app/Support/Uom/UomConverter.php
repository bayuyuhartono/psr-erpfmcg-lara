<?php

namespace App\Support\Uom;

use App\Domain\Masters\Models\Product;
use App\Support\Uom\Exceptions\InvalidConversion;
use App\Support\Uom\Exceptions\UnknownUnit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Turns a quantity in any of a product's units into base units (F-01).
 *
 * The base unit is the only unit stock is ever stored in. Everything else —
 * cartons on a purchase order, packs on an invoice — is converted on the way
 * in and formatted on the way out. `stock_ledger` knows nothing but `qty_base`.
 */
final class UomConverter
{
    private const QUANTITY_SCALE = 4;

    /** How many base units one $uom contains. */
    public function factorFor(Product $product, string $uom): BigDecimal
    {
        if ($uom === $product->base_uom) {
            return $this->quantity(1);
        }

        $unit = $product->productUoms->firstWhere('uom', $uom)
            ?? $product->productUoms()->where('uom', $uom)->first();

        if (! $unit) {
            throw new UnknownUnit(sprintf(
                'Product %s has no unit "%s". Its base unit is "%s".',
                $product->sku,
                $uom,
                $product->base_uom,
            ));
        }

        $factor = $this->quantity($unit->qty_in_base);

        if (! $factor->isPositive()) {
            throw new InvalidConversion(sprintf('Unit "%s" of product %s converts to %s base units.', $uom, $product->sku, $factor));
        }

        return $factor;
    }

    public function toBase(Product $product, string $uom, BigDecimal|string|int $quantity): BigDecimal
    {
        return $this->quantity(BigDecimal::of($quantity)->multipliedBy($this->factorFor($product, $uom)));
    }

    /** The four values a transaction line stores. Always write all four. */
    public function snapshot(Product $product, string $uom, BigDecimal|string|int $quantity): UomSnapshot
    {
        $factor = $this->factorFor($product, $uom);
        $quantity = $this->quantity($quantity);

        return new UomSnapshot(
            $uom,
            $quantity,
            $factor,
            $this->quantity($quantity->multipliedBy($factor)),
        );
    }

    private function quantity(BigDecimal|string|int $value): BigDecimal
    {
        return BigDecimal::of($value)->toScale(self::QUANTITY_SCALE, RoundingMode::HALF_UP);
    }
}
