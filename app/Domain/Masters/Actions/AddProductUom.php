<?php

namespace App\Domain\Masters\Actions;

use App\Domain\Masters\Models\Product;
use App\Domain\Masters\Models\ProductUom;
use App\Support\Posting\PostingTransaction;
use App\Support\Uom\Exceptions\InvalidConversion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Declares one of a product's selling or buying units — 1 CARTON = 144 PCS.
 *
 * Correcting a factor later is allowed and safe: documents snapshot the factor
 * onto their lines, so changing it moves future lines only. What is not
 * allowed is a factor that would make the base unit mean something other than
 * itself, or two units both claiming to be the default.
 */
final class AddProductUom
{
    public function __invoke(
        Product $product,
        string $uom,
        BigDecimal|string|int $quantityInBase,
        ?string $barcode = null,
        bool $defaultForPurchase = false,
        bool $defaultForSale = false,
    ): ProductUom {
        $factor = BigDecimal::of($quantityInBase)->toScale(4, RoundingMode::HALF_UP);

        if (! $factor->isPositive()) {
            throw new InvalidConversion(sprintf('Unit "%s" must contain a positive number of base units, got %s.', $uom, $factor));
        }

        if ($uom === $product->base_uom && ! $factor->isEqualTo(1)) {
            throw new InvalidConversion(sprintf(
                'The base unit "%s" contains exactly one of itself, not %s.',
                $uom,
                $factor,
            ));
        }

        return PostingTransaction::run(function () use ($product, $uom, $factor, $barcode, $defaultForPurchase, $defaultForSale): ProductUom {
            // One default each way, or order entry has to guess which unit the
            // user meant.
            if ($defaultForPurchase) {
                $this->clearDefault($product, 'default_for_purchase', $uom);
            }

            if ($defaultForSale) {
                $this->clearDefault($product, 'default_for_sale', $uom);
            }

            $unit = ProductUom::updateOrCreate(
                ['product_id' => $product->getKey(), 'uom' => $uom],
                [
                    'qty_in_base' => $factor,
                    'barcode' => $barcode,
                    'default_for_purchase' => $defaultForPurchase,
                    'default_for_sale' => $defaultForSale,
                ],
            );

            $product->unsetRelation('productUoms');

            return $unit;
        });
    }

    private function clearDefault(Product $product, string $column, string $except): void
    {
        ProductUom::where('product_id', $product->getKey())
            ->where('uom', '!=', $except)
            ->where($column, true)
            ->get()
            ->each(fn (ProductUom $unit) => $unit->update([$column => false]));
    }
}
