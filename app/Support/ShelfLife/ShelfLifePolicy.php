<?php

namespace App\Support\ShelfLife;

use App\Domain\Masters\Models\Product;
use App\Support\ShelfLife\Exceptions\ShelfLifeTooShort;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The two shelf-life rules, in one place (F-03, F-10, F-20).
 *
 * Both are hard validations, server-side, not warnings on a screen. A
 * distributor that accepts short-dated goods from a principal has bought stock
 * it cannot sell, and one that ships short-dated goods to an outlet gets them
 * back as a return — usually after the claim window against the principal has
 * closed.
 */
final class ShelfLifePolicy
{
    /** Expiry is production date plus shelf life. Null when either is unknown. */
    public function expiryFor(Product $product, ?DateTimeInterface $productionDate): ?CarbonImmutable
    {
        if ($productionDate === null || $product->shelf_life_days === null) {
            return null;
        }

        return CarbonImmutable::instance($productionDate)->startOfDay()->addDays((int) $product->shelf_life_days);
    }

    public function remainingDays(DateTimeInterface $expiry, DateTimeInterface $on): int
    {
        return (int) CarbonImmutable::instance($on)->startOfDay()
            ->diffInDays(CarbonImmutable::instance($expiry)->startOfDay(), false);
    }

    /**
     * Blocks a goods receipt whose remaining life is below
     * `min_life_on_receipt_pct` of the product's shelf life (F-10).
     */
    public function assertAcceptableOnReceipt(Product $product, ?DateTimeInterface $expiry, DateTimeInterface $on): void
    {
        if (! $product->track_expiry || $product->min_life_on_receipt_pct === null) {
            return;
        }

        if ($expiry === null) {
            throw new ShelfLifeTooShort(sprintf(
                'Product %s is expiry-tracked with a minimum life on receipt, so the goods need an expiry date.',
                $product->sku,
            ));
        }

        $remaining = $this->remainingDays($expiry, $on);
        $required = BigDecimal::of((int) $product->shelf_life_days)
            ->multipliedBy(BigDecimal::of($product->min_life_on_receipt_pct))
            ->dividedBy(100, 4, RoundingMode::HALF_UP);

        if (BigDecimal::of($remaining)->isLessThan($required)) {
            throw new ShelfLifeTooShort(sprintf(
                'Product %s expires %s, leaving %d of the %s days required (%s%% of a %d day shelf life). Reject the goods or get an exception from the principal.',
                $product->sku,
                CarbonImmutable::instance($expiry)->format('Y-m-d'),
                $remaining,
                $required->stripTrailingZeros(),
                BigDecimal::of($product->min_life_on_receipt_pct)->stripTrailingZeros(),
                (int) $product->shelf_life_days,
            ));
        }
    }

    /**
     * Whether a batch may still be picked (F-20). Returns a boolean rather than
     * throwing: this filters the picking pool, and a batch below the threshold
     * is not offered rather than reported.
     */
    public function isShippable(Product $product, ?DateTimeInterface $expiry, DateTimeInterface $on): bool
    {
        if (! $product->track_expiry || $expiry === null) {
            return true;
        }

        $remaining = $this->remainingDays($expiry, $on);

        // Expired goods never ship, whatever the threshold says — or whether
        // one is configured at all.
        if ($remaining <= 0) {
            return false;
        }

        return $product->min_life_on_ship_days === null
            || $remaining >= (int) $product->min_life_on_ship_days;
    }
}
