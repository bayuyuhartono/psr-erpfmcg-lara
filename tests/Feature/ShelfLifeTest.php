<?php

namespace Tests\Feature;

use App\Domain\Inventory\Actions\ResolveStockBatch;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Masters\Models\Product;
use App\Support\ShelfLife\Exceptions\InvalidBatchData;
use App\Support\ShelfLife\Exceptions\InvalidShelfLifeConfiguration;
use App\Support\ShelfLife\Exceptions\ShelfLifeTooShort;
use App\Support\ShelfLife\ShelfLifePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShelfLifeTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiry_is_computed_from_the_production_date(): void
    {
        $product = $this->perishable(['shelf_life_days' => 180]);

        $expiry = app(ShelfLifePolicy::class)->expiryFor($product, new CarbonImmutable('2026-08-26'));

        $this->assertSame('2027-02-22', $expiry->format('Y-m-d'));
    }

    public function test_short_dated_goods_are_rejected_at_receipt_not_flagged(): void
    {
        // 100 day shelf life, principal must deliver with 70% left. Goods
        // expiring in 60 days have 60%.
        $product = $this->perishable(['shelf_life_days' => 100, 'min_life_on_receipt_pct' => '70.00']);

        try {
            app(ShelfLifePolicy::class)->assertAcceptableOnReceipt(
                $product,
                new CarbonImmutable('2026-10-25'),
                new CarbonImmutable('2026-08-26'),
            );
            $this->fail('Expected the receipt to be blocked.');
        } catch (ShelfLifeTooShort $exception) {
            $this->assertStringContainsString('leaving 60 of the 70 days required', $exception->getMessage());
        }
    }

    public function test_goods_exactly_on_the_receipt_threshold_are_accepted(): void
    {
        $product = $this->perishable(['shelf_life_days' => 100, 'min_life_on_receipt_pct' => '70.00']);

        app(ShelfLifePolicy::class)->assertAcceptableOnReceipt(
            $product,
            new CarbonImmutable('2026-11-04'),
            new CarbonImmutable('2026-08-26'),
        );

        $this->addToAssertionCount(1);
    }

    public function test_batches_below_the_shipping_threshold_leave_the_picking_pool(): void
    {
        $product = $this->perishable(['shelf_life_days' => 180, 'min_life_on_ship_days' => 30]);
        $policy = app(ShelfLifePolicy::class);
        $today = new CarbonImmutable('2026-08-26');

        $this->assertTrue($policy->isShippable($product, new CarbonImmutable('2026-09-26'), $today));
        $this->assertFalse($policy->isShippable($product, new CarbonImmutable('2026-09-10'), $today));
    }

    public function test_expired_stock_never_ships_even_with_no_threshold_set(): void
    {
        $product = $this->perishable(['shelf_life_days' => 180]);

        $this->assertFalse(app(ShelfLifePolicy::class)->isShippable(
            $product,
            new CarbonImmutable('2026-08-25'),
            new CarbonImmutable('2026-08-26'),
        ));
    }

    public function test_a_product_that_is_not_perishable_is_always_shippable(): void
    {
        $product = Product::factory()->create();

        $this->assertTrue(app(ShelfLifePolicy::class)->isShippable($product, new CarbonImmutable('2020-01-01'), new CarbonImmutable('2026-08-26')));
    }

    public function test_resolving_a_batch_fills_in_the_expiry_and_reuses_it_next_time(): void
    {
        $product = $this->perishable(['shelf_life_days' => 180]);
        $resolve = app(ResolveStockBatch::class);

        $first = $resolve($product, 'B-2608', new CarbonImmutable('2026-08-26'), productionDate: new CarbonImmutable('2026-08-01'));
        $second = $resolve($product, 'B-2608', new CarbonImmutable('2026-09-01'), productionDate: new CarbonImmutable('2026-08-01'));

        $this->assertSame('2027-01-28', $first->expiry_date->format('Y-m-d'));
        $this->assertTrue($first->is($second));
        $this->assertSame(1, StockBatch::count());
    }

    public function test_the_same_batch_arriving_with_a_different_expiry_is_refused(): void
    {
        $product = $this->perishable(['shelf_life_days' => 180]);
        $resolve = app(ResolveStockBatch::class);
        $resolve($product, 'B-2608', new CarbonImmutable('2026-08-26'), expiryDate: new CarbonImmutable('2027-01-28'));

        $this->expectException(InvalidBatchData::class);
        $this->expectExceptionMessageMatches('/already recorded as expiring 2027-01-28, not 2027-02-28/');

        $resolve($product, 'B-2608', new CarbonImmutable('2026-08-26'), expiryDate: new CarbonImmutable('2027-02-28'));
    }

    public function test_a_batch_tracked_product_will_not_receive_without_a_batch_number(): void
    {
        $this->expectException(InvalidBatchData::class);

        app(ResolveStockBatch::class)($this->perishable(['shelf_life_days' => 180]), null, new CarbonImmutable('2026-08-26'));
    }

    public function test_receiving_through_the_batch_action_still_enforces_the_threshold(): void
    {
        // The rule cannot be skipped by going through a different screen.
        $product = $this->perishable(['shelf_life_days' => 100, 'min_life_on_receipt_pct' => '70.00']);

        $this->expectException(ShelfLifeTooShort::class);

        app(ResolveStockBatch::class)(
            $product,
            'B-SHORT',
            new CarbonImmutable('2026-08-26'),
            expiryDate: new CarbonImmutable('2026-10-25'),
        );
    }

    public function test_expiry_tracking_without_batch_tracking_is_refused(): void
    {
        $this->expectException(InvalidShelfLifeConfiguration::class);
        $this->expectExceptionMessageMatches('/nowhere to record one/');

        Product::factory()->create(['track_batch' => false, 'track_expiry' => true]);
    }

    public function test_a_receipt_percentage_with_no_shelf_life_is_refused(): void
    {
        $this->expectException(InvalidShelfLifeConfiguration::class);
        $this->expectExceptionMessageMatches('/percentage of a shelf life it does not have/');

        $this->perishable(['min_life_on_receipt_pct' => '70.00']);
    }

    public function test_an_unknown_issue_method_is_refused(): void
    {
        $this->expectException(InvalidShelfLifeConfiguration::class);

        Product::factory()->create(['issue_method' => 'lifo']);
    }

    /** @param array<string, mixed> $attributes */
    private function perishable(array $attributes): Product
    {
        return Product::factory()->create($attributes + ['track_batch' => true, 'track_expiry' => true]);
    }
}
