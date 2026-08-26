<?php

namespace App\Support\Pricing;

use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Category;
use App\Domain\Masters\Models\DiscountRule;
use App\Domain\Masters\Models\PriceList;
use App\Domain\Masters\Models\PriceListItem;
use App\Domain\Masters\Models\Product;
use App\Support\Pricing\Exceptions\InvalidDiscount;
use App\Support\Pricing\Exceptions\NoPriceFound;
use App\Support\Pricing\Exceptions\TooManyDiscountTiers;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Works out what to charge for one line, and hands back the numbers to copy
 * onto it (F-32).
 *
 * The rule that shapes everything here: **tiered discounts compound
 * sequentially**. 5% then 3% then 2% leaves 90.25% of the price, not 90%.
 * Summing them is the single most common way a distributor's invoices stop
 * matching their principal's promotion terms.
 */
final class PriceResolver
{
    private const MAX_PERCENT_TIERS = 3;

    private const MONEY_SCALE = 2;

    public function quote(
        Product $product,
        string $uom,
        BigDecimal|string|int $quantity,
        ?BusinessPartner $customer = null,
        ?DateTimeInterface $on = null,
        ?PriceList $priceList = null,
    ): PriceQuote {
        $on = CarbonImmutable::instance($on ?? CarbonImmutable::now())->startOfDay();
        $quantity = BigDecimal::of($quantity)->toScale(4, RoundingMode::HALF_UP);

        $priceList = $priceList ?? $this->priceListFor($customer, $product, $uom);
        $this->assertUsable($priceList, $on);

        $unitPrice = $this->unitPriceFor($priceList, $product, $uom, $quantity);
        $gross = $this->money($quantity->multipliedBy($unitPrice));

        $rules = $this->rulesFor($product, $customer, $on, $quantity, $gross);
        [$percents, $flatAmounts] = $this->split($rules);

        if (count($percents) > self::MAX_PERCENT_TIERS) {
            throw new TooManyDiscountTiers(sprintf(
                '%d percentage discounts match %s, but a line records at most %d. Rules: %s.',
                count($percents),
                $product->sku,
                self::MAX_PERCENT_TIERS,
                $rules->pluck('name')->implode(', '),
            ));
        }

        $net = $unitPrice;
        foreach ($percents as $percent) {
            // One after another, each on what is left of the price.
            $net = $net->multipliedBy(BigDecimal::of(100)->minus($percent))->dividedBy(100, 6, RoundingMode::HALF_UP);
        }

        $discountAmount = $this->money(array_reduce(
            $flatAmounts,
            fn (BigDecimal $carry, BigDecimal $amount): BigDecimal => $carry->plus($amount),
            BigDecimal::zero(),
        ));

        $lineTotal = $this->money($quantity->multipliedBy($net))->minus($discountAmount);

        if ($lineTotal->isNegative()) {
            throw new InvalidDiscount(sprintf(
                'Discounts on %s come to more than the line is worth (%s off %s).',
                $product->sku,
                $discountAmount,
                $gross,
            ));
        }

        return new PriceQuote($unitPrice, $percents, $discountAmount, $quantity, $lineTotal, $priceList->getKey());
    }

    private function priceListFor(?BusinessPartner $customer, Product $product, string $uom): PriceList
    {
        $priceList = $customer?->tier?->priceList;

        if (! $priceList) {
            throw new NoPriceFound(sprintf(
                'No price list applies to %s in %s: %s.',
                $product->sku,
                $uom,
                $customer === null
                    ? 'no customer and no price list were given'
                    : sprintf('customer %s is on no tier, or its tier has no price list', $customer->code),
            ));
        }

        return $priceList;
    }

    private function assertUsable(PriceList $priceList, CarbonImmutable $on): void
    {
        $started = $priceList->starts_on === null || ! $on->lt($priceList->starts_on);
        $notEnded = $priceList->ends_on === null || ! $on->gt($priceList->ends_on);

        if (! $priceList->active || ! $started || ! $notEnded) {
            throw new NoPriceFound(sprintf(
                'Price list "%s" is not in force on %s.',
                $priceList->name,
                $on->format('Y-m-d'),
            ));
        }
    }

    /** Volume bands: the highest band the quantity reaches wins. */
    private function unitPriceFor(PriceList $priceList, Product $product, string $uom, BigDecimal $quantity): BigDecimal
    {
        $item = PriceListItem::query()
            ->where('price_list_id', $priceList->getKey())
            ->where('product_id', $product->getKey())
            ->where('uom', $uom)
            ->where('min_qty', '<=', (string) $quantity)
            ->orderByDesc('min_qty')
            ->first();

        if (! $item) {
            throw new NoPriceFound(sprintf(
                'Price list "%s" has no price for %s in %s at a quantity of %s.',
                $priceList->name,
                $product->sku,
                $uom,
                $quantity->stripTrailingZeros(),
            ));
        }

        return $this->money($item->unit_price);
    }

    /** @return Collection<int, DiscountRule> */
    private function rulesFor(
        Product $product,
        ?BusinessPartner $customer,
        CarbonImmutable $on,
        BigDecimal $quantity,
        BigDecimal $gross,
    ): Collection {
        $date = $on->format('Y-m-d');

        return DiscountRule::query()
            ->where(fn ($query) => $query->whereNull('starts_on')->orWhere('starts_on', '<=', $date))
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
            ->where(fn ($query) => $query->whereNull('min_qty')->orWhere('min_qty', '<=', (string) $quantity))
            ->where(fn ($query) => $query->whereNull('min_amount')->orWhere('min_amount', '<=', (string) $gross))
            ->orderBy('sequence')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (DiscountRule $rule): bool => $this->scopeMatches($rule, $product) && $this->targetMatches($rule, $customer))
            ->values();
    }

    private function scopeMatches(DiscountRule $rule, Product $product): bool
    {
        return match ($rule->scope) {
            'all' => true,
            'product' => $rule->scope_id === $product->getKey(),
            'brand' => $rule->scope_id !== null && $rule->scope_id === $product->brand_id,
            // A rule on "Beverages" covers "Beverages > Coffee". Anything else
            // would mean re-entering every rule on every leaf category.
            'category' => $rule->scope_id !== null && in_array($rule->scope_id, $this->categoryAncestry($product), true),
            default => false,
        };
    }

    private function targetMatches(DiscountRule $rule, ?BusinessPartner $customer): bool
    {
        return match ($rule->target) {
            'all' => true,
            'customer' => $customer !== null && $rule->target_id === $customer->getKey(),
            'tier' => $customer !== null && $rule->target_id !== null && $rule->target_id === $customer->tier_id,
            'territory' => $customer !== null && $rule->target_id !== null && $rule->target_id === $customer->territory_id,
            default => false,
        };
    }

    /** @return list<string> the product's category and everything above it */
    private function categoryAncestry(Product $product): array
    {
        $ancestry = [];
        $category = $product->category;
        $depth = 0;

        while ($category !== null && $depth < 20) {
            $ancestry[] = $category->getKey();
            $category = $category->parent_id === null ? null : Category::find($category->parent_id);
            $depth++;
        }

        return $ancestry;
    }

    /**
     * @param  Collection<int, DiscountRule>  $rules
     * @return array{list<BigDecimal>, list<BigDecimal>}
     */
    private function split(Collection $rules): array
    {
        $percents = [];
        $amounts = [];

        foreach ($rules as $rule) {
            if ($rule->percent !== null && ! BigDecimal::of($rule->percent)->isZero()) {
                $percents[] = BigDecimal::of($rule->percent)->toScale(2, RoundingMode::HALF_UP);
            }

            // A rule's flat amount comes off the line, not off each unit.
            if ($rule->amount !== null && ! BigDecimal::of($rule->amount)->isZero()) {
                $amounts[] = BigDecimal::of($rule->amount)->toScale(self::MONEY_SCALE, RoundingMode::HALF_UP);
            }
        }

        return [$percents, $amounts];
    }

    private function money(BigDecimal|string|int $value): BigDecimal
    {
        return BigDecimal::of($value)->toScale(self::MONEY_SCALE, RoundingMode::HALF_UP);
    }
}
