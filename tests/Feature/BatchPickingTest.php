<?php

namespace Tests\Feature;

use App\Domain\Inventory\Exceptions\NoPickableStock;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\BatchPicker;
use App\Domain\Inventory\Services\PickAllocation;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Product;
use App\Domain\Purchasing\Actions\DraftGoodsReceipt;
use App\Domain\Purchasing\Actions\PostGoodsReceipt;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BatchPickingTest extends TestCase
{
    use RefreshDatabase;

    private BusinessPartner $supplier;

    private Warehouse $warehouse;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CompanySettingsSeeder::class, ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
        $this->supplier = BusinessPartner::factory()->create(['is_supplier' => true]);
        $this->warehouse = Warehouse::factory()->create();
        $this->today = new CarbonImmutable('2026-08-26');
    }

    public function test_nearest_expiry_leaves_first(): void
    {
        $product = $this->perishable();
        $this->receive($product, 'B-LATE', '2027-06-30', '100');
        $this->receive($product, 'B-SOON', '2026-12-31', '60');
        $this->receive($product, 'B-MID', '2027-01-31', '50');

        $picks = app(BatchPicker::class)->pick($product, $this->warehouse->id, '130', $this->today);

        $this->assertSame(
            [['B-SOON', '60.0000'], ['B-MID', '50.0000'], ['B-LATE', '20.0000']],
            $this->summarise($picks),
        );
    }

    public function test_a_batch_below_the_shipping_threshold_is_never_offered(): void
    {
        // 30 days minimum on shipment. The near batch has 20 days left.
        $product = $this->perishable(['min_life_on_ship_days' => 30]);
        $this->receive($product, 'B-NEAR', '2026-09-15', '100');
        $this->receive($product, 'B-GOOD', '2027-01-31', '40');

        $picks = app(BatchPicker::class)->pick($product, $this->warehouse->id, '40', $this->today);

        $this->assertSame([['B-GOOD', '40.0000']], $this->summarise($picks));
    }

    public function test_stock_that_cannot_ship_is_not_counted_as_available(): void
    {
        $product = $this->perishable(['min_life_on_ship_days' => 30]);
        $this->receive($product, 'B-NEAR', '2026-09-15', '100');
        $this->receive($product, 'B-GOOD', '2027-01-31', '40');

        try {
            app(BatchPicker::class)->pick($product, $this->warehouse->id, '60', $this->today);
            $this->fail('Expected the short-dated batch to be excluded from what is available.');
        } catch (NoPickableStock $exception) {
            // 140 on hand, 40 pickable.
            $this->assertStringContainsString('Only 40', $exception->getMessage());
            $this->assertStringContainsString('20 short', $exception->getMessage());
        }
    }

    public function test_a_batch_can_be_taken_out_of_order_with_a_reason(): void
    {
        $product = $this->perishable();
        $this->receive($product, 'B-SOON', '2026-12-31', '60');
        $this->receive($product, 'B-LATE', '2027-06-30', '100');
        $late = StockBatch::where('batch_number', 'B-LATE')->firstOrFail();

        $picks = app(BatchPicker::class)->pick(
            $product, $this->warehouse->id, '20', $this->today,
            forceBatchId: $late->id, overrideReason: 'Customer asked for this production run',
        );

        $this->assertSame([['B-LATE', '20.0000']], $this->summarise($picks));
        $this->assertTrue($picks[0]->isOverride());
        $this->assertSame('Customer asked for this production run', $picks[0]->overrideReason);
    }

    public function test_overriding_without_a_reason_is_refused(): void
    {
        $product = $this->perishable();
        $this->receive($product, 'B-LATE', '2027-06-30', '100');
        $batch = StockBatch::firstOrFail();

        $this->expectException(NoPickableStock::class);
        $this->expectExceptionMessageMatches('/out of order needs a reason/');

        app(BatchPicker::class)->pick($product, $this->warehouse->id, '10', $this->today, forceBatchId: $batch->id);
    }

    public function test_an_override_cannot_reach_past_the_shelf_life_filter(): void
    {
        // The reason explains a preference, not a rule. Short-dated stock
        // still does not go out.
        $product = $this->perishable(['min_life_on_ship_days' => 30]);
        $this->receive($product, 'B-NEAR', '2026-09-15', '100');
        $batch = StockBatch::where('batch_number', 'B-NEAR')->firstOrFail();

        $this->expectException(NoPickableStock::class);
        $this->expectExceptionMessageMatches('/whatever the reason/');

        app(BatchPicker::class)->pick(
            $product, $this->warehouse->id, '10', $this->today,
            forceBatchId: $batch->id, overrideReason: 'The driver is waiting',
        );
    }

    public function test_a_product_without_batches_picks_from_the_plain_balance(): void
    {
        $product = Product::factory()->create(['sku' => 'RICE-01', 'base_uom' => 'KG']);
        $this->receive($product, null, null, '500');

        $picks = app(BatchPicker::class)->pick($product, $this->warehouse->id, '120', $this->today);

        $this->assertCount(1, $picks);
        $this->assertNull($picks[0]->batch);
        $this->assertSame('120.0000', (string) $picks[0]->quantityBase);
    }

    public function test_a_batch_tracked_product_without_expiry_still_picks_across_batches(): void
    {
        // Traceability without a shelf life — soap, detergent. There is no
        // expiry to order by and nothing for the shelf-life filter to exclude,
        // so a pick simply draws down the batches it finds.
        $product = Product::factory()->create([
            'sku' => 'SABUN-01', 'base_uom' => 'PCS', 'track_batch' => true, 'track_expiry' => false,
        ]);
        $this->receive($product, 'B-001', null, '30');
        $this->receive($product, 'B-002', null, '100');

        $picks = app(BatchPicker::class)->pick($product, $this->warehouse->id, '50', $this->today);

        $this->assertSame('50.0000', (string) array_reduce(
            $picks,
            fn ($carry, $pick) => $carry->plus($pick->quantityBase),
            BigDecimal::zero(),
        )->toScale(4));
        $this->assertCount(2, $picks);
    }

    /** @param  list<PickAllocation>  $picks @return list<array{string, string}> */
    private function summarise(array $picks): array
    {
        return array_map(
            fn (PickAllocation $pick): array => [$pick->batch?->batch_number ?? '—', (string) $pick->quantityBase],
            $picks,
        );
    }

    /** @param  array<string, mixed>  $attributes */
    private function perishable(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + [
            'sku' => 'KOPI-'.fake()->unique()->numberBetween(1, 9999),
            'base_uom' => 'PCS', 'track_batch' => true, 'track_expiry' => true, 'shelf_life_days' => 365,
        ]);
    }

    private function receive(Product $product, ?string $batch, ?string $expiry, string $qty): void
    {
        $receipt = app(DraftGoodsReceipt::class)($this->supplier, $this->warehouse->id, [[
            'product' => $product, 'qty' => $qty, 'unit_cost' => '2500',
            'batch_number' => $batch,
            'expiry_date' => $expiry === null ? null : new CarbonImmutable($expiry),
        ]], null, $this->today);

        app(PostGoodsReceipt::class)($receipt);
    }
}
