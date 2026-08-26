<?php

namespace Tests\Feature;

use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Category;
use App\Domain\Masters\Models\CustomerTier;
use App\Domain\Masters\Models\DiscountRule;
use App\Domain\Masters\Models\PriceList;
use App\Domain\Masters\Models\PriceListItem;
use App\Domain\Masters\Models\Product;
use App\Support\Pricing\Exceptions\NoPriceFound;
use App\Support\Pricing\Exceptions\TooManyDiscountTiers;
use App\Support\Pricing\PriceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PricingTest extends TestCase
{
    use RefreshDatabase;

    private PriceList $priceList;

    private CustomerTier $tier;

    private BusinessPartner $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->priceList = PriceList::create(['name' => 'Wholesale 2026', 'active' => true]);
        $this->tier = CustomerTier::create(['name' => 'Wholesale', 'price_list_id' => $this->priceList->id]);
        $this->customer = BusinessPartner::factory()->create(['tier_id' => $this->tier->id]);
        $this->product = Product::factory()->create(['sku' => 'KOPI-01']);
        $this->price('CARTON', '0', '100000.00');
    }

    public function test_the_price_comes_from_the_customers_tier(): void
    {
        $quote = $this->quote('2');

        $this->assertSame('100000.00', (string) $quote->unitPrice);
        $this->assertSame('200000.00', (string) $quote->lineTotal);
        $this->assertSame($this->priceList->id, $quote->priceListId);
    }

    public function test_the_highest_volume_band_the_quantity_reaches_wins(): void
    {
        $this->price('CARTON', '10', '95000.00');
        $this->price('CARTON', '50', '90000.00');

        $this->assertSame('100000.00', (string) $this->quote('9')->unitPrice);
        $this->assertSame('95000.00', (string) $this->quote('10')->unitPrice);
        $this->assertSame('90000.00', (string) $this->quote('60')->unitPrice);
    }

    public function test_tiered_discounts_compound_instead_of_summing(): void
    {
        $this->discount('Principal promo', '5.00', sequence: 1);
        $this->discount('Tier discount', '3.00', sequence: 2);
        $this->discount('Volume', '2.00', sequence: 3);

        $quote = $this->quote('1');

        // 5 then 3 then 2 leaves 90.307%, not 90%. Summed, this line would be
        // 90,000 and every invoice would disagree with the principal's terms.
        $this->assertSame(['5.00', '3.00', '2.00'], array_map('strval', $quote->discountPercents));
        $this->assertSame('90307.00', (string) $quote->lineTotal);
        $this->assertSame('9.69', (string) $quote->effectiveDiscountPercent());
    }

    public function test_discounts_apply_in_their_declared_sequence(): void
    {
        $this->discount('Second', '10.00', sequence: 2);
        $this->discount('First', '20.00', sequence: 1);

        $this->assertSame(['20.00', '10.00'], array_map('strval', $this->quote('1')->discountPercents));
    }

    public function test_a_rule_scoped_to_a_parent_category_reaches_its_children(): void
    {
        $drinks = Category::create(['name' => 'Beverages']);
        $coffee = Category::create(['name' => 'Coffee', 'parent_id' => $drinks->id]);
        $this->product->update(['category_id' => $coffee->id]);

        $this->discount('Beverages promo', '10.00', scope: 'category', scopeId: $drinks->id);

        $this->assertSame('90000.00', (string) $this->quote('1')->lineTotal);
    }

    public function test_a_rule_aimed_at_another_customer_does_not_apply(): void
    {
        $other = BusinessPartner::factory()->create();
        $this->discount('Special deal', '50.00', target: 'customer', targetId: $other->id);

        $this->assertSame('100000.00', (string) $this->quote('1')->lineTotal);
    }

    public function test_a_rule_aimed_at_the_customers_tier_does_apply(): void
    {
        $this->discount('Wholesale terms', '15.00', target: 'tier', targetId: $this->tier->id);

        $this->assertSame('85000.00', (string) $this->quote('1')->lineTotal);
    }

    public function test_a_rule_below_its_minimum_quantity_stays_out(): void
    {
        $this->discount('Bulk', '10.00', minQty: '10');

        $this->assertSame([], $this->quote('9')->discountPercents);
        $this->assertSame(['10.00'], array_map('strval', $this->quote('10')->discountPercents));
    }

    public function test_an_expired_rule_stays_out(): void
    {
        $this->discount('Ramadan promo', '25.00', startsOn: '2026-03-01', endsOn: '2026-04-30');

        $this->assertSame([], $this->quote('1', on: '2026-08-26')->discountPercents);
        $this->assertSame(['25.00'], array_map('strval', $this->quote('1', on: '2026-04-01')->discountPercents));
    }

    public function test_a_flat_amount_comes_off_the_line_after_the_percentages(): void
    {
        $this->discount('Percent', '10.00', sequence: 1);
        $this->discount('Rebate', null, amount: '5000.00', sequence: 2);

        $quote = $this->quote('1');

        $this->assertSame('5000.00', (string) $quote->discountAmount);
        $this->assertSame('85000.00', (string) $quote->lineTotal);
    }

    public function test_more_percentage_tiers_than_a_line_can_hold_is_refused(): void
    {
        foreach (range(1, 4) as $sequence) {
            $this->discount("Tier {$sequence}", '5.00', sequence: $sequence);
        }

        $this->expectException(TooManyDiscountTiers::class);
        $this->expectExceptionMessageMatches('/at most 3/');

        $this->quote('1');
    }

    public function test_a_customer_on_no_tier_has_no_price(): void
    {
        $this->customer->update(['tier_id' => null]);

        $this->expectException(NoPriceFound::class);
        $this->expectExceptionMessageMatches('/is on no tier/');

        $this->quote('1');
    }

    public function test_a_price_list_out_of_its_date_window_is_not_used(): void
    {
        $this->priceList->update(['starts_on' => '2026-09-01']);

        $this->expectException(NoPriceFound::class);
        $this->expectExceptionMessageMatches('/not in force on 2026-08-26/');

        $this->quote('1', on: '2026-08-26');
    }

    public function test_the_quote_maps_onto_the_columns_a_line_stores(): void
    {
        $this->discount('One', '5.00', sequence: 1);
        $this->discount('Two', '3.00', sequence: 2);

        $attributes = array_map('strval', $this->quote('2')->toLineAttributes());

        $this->assertSame([
            'unit_price' => '100000.00',
            'discount1_percent' => '5.00',
            'discount2_percent' => '3.00',
            'discount3_percent' => '0.00',
            'discount_amount' => '0.00',
            'line_total' => '184300.00',
        ], $attributes);
    }

    private function quote(string $quantity, string $on = '2026-08-26')
    {
        return app(PriceResolver::class)->quote(
            $this->product->fresh(),
            'CARTON',
            $quantity,
            $this->customer->fresh(),
            new CarbonImmutable($on),
        );
    }

    private function price(string $uom, string $minQty, string $unitPrice): void
    {
        PriceListItem::create([
            'price_list_id' => $this->priceList->id, 'product_id' => $this->product->id,
            'uom' => $uom, 'min_qty' => $minQty, 'unit_price' => $unitPrice,
        ]);
    }

    private function discount(
        string $name,
        ?string $percent,
        ?string $amount = null,
        int $sequence = 1,
        string $scope = 'all',
        ?string $scopeId = null,
        string $target = 'all',
        ?string $targetId = null,
        ?string $minQty = null,
        ?string $startsOn = null,
        ?string $endsOn = null,
    ): void {
        DiscountRule::create([
            'name' => $name, 'scope' => $scope, 'scope_id' => $scopeId,
            'target' => $target, 'target_id' => $targetId,
            'min_qty' => $minQty, 'percent' => $percent, 'amount' => $amount,
            'sequence' => $sequence, 'starts_on' => $startsOn, 'ends_on' => $endsOn,
        ]);
    }
}
