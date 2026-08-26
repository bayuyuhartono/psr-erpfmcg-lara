<?php

namespace Tests\Feature;

use App\Domain\Inventory\Actions\ApproveStockAdjustment;
use App\Domain\Inventory\Actions\CloseStockCount;
use App\Domain\Inventory\Actions\OpenStockCount;
use App\Domain\Inventory\Actions\PostStockAdjustment;
use App\Domain\Inventory\Actions\ReceiveStockTransfer;
use App\Domain\Inventory\Actions\ShipStockTransfer;
use App\Domain\Inventory\Exceptions\InvalidStockAdjustment;
use App\Domain\Inventory\Exceptions\InvalidStockCount;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAdjustmentLine;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\ExpiryAlerts;
use App\Domain\Inventory\Services\StockCard;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Product;
use App\Domain\Purchasing\Actions\DraftGoodsReceipt;
use App\Domain\Purchasing\Actions\PostGoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Models\User;
use App\Support\Documents\Exceptions\TransitionNotPermitted;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InventoryOperationsTest extends TestCase
{
    use RefreshDatabase;

    private BusinessPartner $supplier;

    private Warehouse $warehouse;

    private Product $product;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class,
        ]);
        $this->supplier = BusinessPartner::factory()->create(['is_supplier' => true]);
        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
        $this->product = Product::factory()->create(['sku' => 'KOPI-01', 'base_uom' => 'PCS']);
        $this->today = new CarbonImmutable('2026-08-26');
    }

    // 4.4 — stock card

    public function test_the_stock_card_runs_a_balance_and_names_the_source_document(): void
    {
        $receipt = $this->receive('100', '2500');
        $this->adjust('-10', 'damage');

        $card = app(StockCard::class)->for($this->product, $this->warehouse->id);

        $this->assertCount(2, $card);
        $this->assertSame('goods_receipt', $card[0]->referenceType);
        $this->assertSame($receipt->document_number, $card[0]->documentNumber);
        $this->assertSame('100.0000', (string) $card[0]->runningQuantity);
        $this->assertSame('in', $card[0]->movement());

        $this->assertSame('adjustment', $card[1]->referenceType);
        $this->assertStringStartsWith('ADJ/', $card[1]->documentNumber);
        $this->assertSame('90.0000', (string) $card[1]->runningQuantity);
        $this->assertSame('225000.00', (string) $card[1]->runningValue);
    }

    public function test_a_card_for_a_window_still_starts_from_the_right_number(): void
    {
        $this->receive('100', '2500');
        $this->receive('40', '2500', new CarbonImmutable('2026-09-05'));

        $card = app(StockCard::class)->for(
            $this->product, $this->warehouse->id,
            from: new CarbonImmutable('2026-09-01'), to: new CarbonImmutable('2026-09-30'),
        );

        // Only September's movement is listed, but it runs on from August.
        $this->assertCount(1, $card);
        $this->assertSame('140.0000', (string) $card[0]->runningQuantity);
    }

    // 4.5 / 4.6 — counting

    public function test_the_count_freezes_what_the_system_thought_and_lets_trading_continue(): void
    {
        $this->receive('100', '2500');

        $count = app(OpenStockCount::class)($this->warehouse, null, $this->today);
        $this->assertSame('counting', $count->status);
        $this->assertSame('100.0000', (string) $count->stockCountLines()->value('system_qty'));

        // The warehouse keeps working: 40 more arrive mid-count.
        $this->receive('40', '2500');

        // The frozen figure has not moved, so the variance stays a real
        // discrepancy rather than a day's trading.
        $this->assertSame('100.0000', (string) $count->stockCountLines()->value('system_qty'));
        $this->assertSame('140.0000', (string) StockBalance::firstOrFail()->qty_base);
    }

    public function test_a_count_that_agrees_produces_no_adjustment(): void
    {
        $this->receive('100', '2500');
        $count = app(OpenStockCount::class)($this->warehouse, null, $this->today);
        $count->stockCountLines()->first()->update(['counted_qty' => '100']);

        $adjustment = app(CloseStockCount::class)($count);

        $this->assertNull($adjustment);
        $this->assertSame('posted', $count->fresh()->status);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_a_count_with_lines_nobody_counted_cannot_be_closed(): void
    {
        $this->receive('100', '2500');
        $count = app(OpenStockCount::class)($this->warehouse, null, $this->today);

        $this->expectException(InvalidStockCount::class);
        $this->expectExceptionMessageMatches('/including the empty shelves/');

        app(CloseStockCount::class)($count);
    }

    public function test_a_variance_becomes_an_adjustment_that_waits_for_approval(): void
    {
        $this->receive('100', '2500');
        $count = app(OpenStockCount::class)($this->warehouse, null, $this->today);
        $count->stockCountLines()->first()->update(['counted_qty' => '94']);

        $adjustment = app(CloseStockCount::class)($count);

        $this->assertSame('draft', $adjustment->status);
        $this->assertSame('count_variance', $adjustment->reason);
        $this->assertSame('-6.0000', (string) $adjustment->stockAdjustmentLines()->value('qty_base'));

        // Nothing has moved yet. A variance is a question, not a fact.
        $this->assertSame('100.0000', (string) StockBalance::firstOrFail()->qty_base);

        try {
            app(PostStockAdjustment::class)($adjustment);
            $this->fail('Expected posting to require approval first.');
        } catch (InvalidStockAdjustment $exception) {
            $this->assertStringContainsString('once someone has approved', $exception->getMessage());
        }

        $supervisor = $this->userWithRole('Warehouse Supervisor');
        app(ApproveStockAdjustment::class)($adjustment, $supervisor);
        app(PostStockAdjustment::class)($adjustment->fresh(), $supervisor);

        $this->assertSame('94.0000', (string) StockBalance::firstOrFail()->qty_base);
        $this->assertSame($supervisor->id, $adjustment->fresh()->approved_by);
    }

    public function test_approving_a_variance_needs_the_permission(): void
    {
        $this->receive('100', '2500');
        $count = app(OpenStockCount::class)($this->warehouse, null, $this->today);
        $count->stockCountLines()->first()->update(['counted_qty' => '94']);
        $adjustment = app(CloseStockCount::class)($count);

        $this->expectException(TransitionNotPermitted::class);

        app(ApproveStockAdjustment::class)($adjustment, $this->userWithRole('Sales Admin'));
    }

    // 4.7 — transfers

    public function test_a_transfer_moves_stock_without_changing_what_it_is_worth(): void
    {
        $this->receive('100', '2000');
        $this->receive('100', '3000');
        $this->assertSame('2500.0000', (string) ProductCost::firstOrFail()->average_cost);

        $destination = Warehouse::factory()->create(['code' => 'WH-02']);
        $transfer = $this->transfer($destination, '60');
        $mover = $this->userWithRole('Warehouse Supervisor');

        app(ShipStockTransfer::class)($transfer, $mover);
        app(ReceiveStockTransfer::class)($transfer->fresh(), $mover);

        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame('140.0000', (string) StockBalance::where('warehouse_id', $this->warehouse->id)->value('qty_base'));
        $this->assertSame('60.0000', (string) StockBalance::where('warehouse_id', $destination->id)->value('qty_base'));

        // Moving goods between warehouses is not a profit: quantity, value and
        // average all come out where they went in.
        $cost = ProductCost::firstOrFail();
        $this->assertSame('200.0000', (string) $cost->total_qty);
        $this->assertSame('500000.00', (string) $cost->total_value);
        $this->assertSame('2500.0000', (string) $cost->average_cost);
    }

    public function test_a_transfer_posts_no_journal(): void
    {
        $this->receive('100', '2500');
        $destination = Warehouse::factory()->create(['code' => 'WH-02']);
        $transfer = $this->transfer($destination, '40');
        $mover = $this->userWithRole('Warehouse Supervisor');

        app(ShipStockTransfer::class)($transfer, $mover);
        app(ReceiveStockTransfer::class)($transfer->fresh(), $mover);

        // Both legs hit the same inventory account, so there is nothing to say.
        $this->assertSame(1, DB::table('journal_entries')->count());   // the receipt's
    }

    // 4.8 — expiry alerts

    public function test_expiry_alerts_group_by_remaining_life_and_report_rupiah(): void
    {
        $product = Product::factory()->create([
            'sku' => 'SUSU-01', 'base_uom' => 'PCS', 'track_batch' => true,
            'track_expiry' => true, 'shelf_life_days' => 365,
        ]);

        // Received in good order in August, then looked at a month later —
        // which is how stock actually expires: on the shelf, not on arrival.
        $this->receiveBatch($product, 'B-GONE', '2026-09-20', '10', '2000');
        $this->receiveBatch($product, 'B-SOON', '2026-10-10', '100', '2000');
        $this->receiveBatch($product, 'B-LATER', '2026-12-01', '50', '2000');
        $this->receiveBatch($product, 'B-SAFE', '2027-06-30', '400', '2000');

        $bands = collect(app(ExpiryAlerts::class)(null, new CarbonImmutable('2026-09-26')))->keyBy('label');

        $this->assertSame('20000.00', (string) $bands['Already expired']->value);
        $this->assertSame('200000.00', (string) $bands['Within 30 days']->value);
        $this->assertSame('100000.00', (string) $bands['Within 90 days']->value);
        // Beyond the horizon, so not reported at all.
        $this->assertSame('0.00', (string) $bands['Within 60 days']->value);
        $this->assertSame('B-SOON', $bands['Within 30 days']->items[0]['batch']);
    }

    // 4.9 — reservation cleanup

    public function test_expired_reservations_stop_holding_stock(): void
    {
        $live = $this->reservation(now()->addDay());
        $stale = $this->reservation(now()->subHour());

        $this->artisan('stock:release-expired-reservations')
            ->expectsOutputToContain('Released 1')
            ->assertExitCode(0);

        $this->assertSame('active', $live->fresh()->status);
        $this->assertSame('released', $stale->fresh()->status);
    }

    public function test_the_cleanup_can_report_without_releasing(): void
    {
        $stale = $this->reservation(now()->subHour());

        $this->artisan('stock:release-expired-reservations', ['--check' => true])->assertExitCode(0);

        $this->assertSame('active', $stale->fresh()->status);
    }

    private function reservation(\DateTimeInterface $expiresAt): StockReservation
    {
        return StockReservation::create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'qty_base' => '10',
            'ref_type' => 'sales_order',
            'ref_id' => (string) Str::uuid7(),
            'expires_at' => $expiresAt,
        ]);
    }

    private function transfer(Warehouse $destination, string $qty): StockTransfer
    {
        $transfer = StockTransfer::create([
            'document_number' => 'TRF-'.Str::upper(Str::random(6)),
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $destination->id,
            'transfer_date' => $this->today->format('Y-m-d'),
            'transfer_reason' => 'rebalance',
        ]);

        StockTransferLine::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'qty_base' => $qty,
        ]);

        return $transfer->refresh();
    }

    private function adjust(string $qty, string $reason): void
    {
        $adjustment = StockAdjustment::create([
            'document_number' => 'ADJ/2608/'.Str::upper(Str::random(6)),
            'warehouse_id' => $this->warehouse->id,
            'adjustment_date' => $this->today->format('Y-m-d'),
            'reason' => $reason,
        ]);

        StockAdjustmentLine::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $this->product->id,
            'qty_base' => $qty,
        ]);

        $supervisor = $this->userWithRole('Warehouse Supervisor');
        app(ApproveStockAdjustment::class)($adjustment, $supervisor);
        app(PostStockAdjustment::class)($adjustment->fresh(), $supervisor);
    }

    private function receive(string $qty, string $cost, ?CarbonImmutable $on = null): GoodsReceipt
    {
        $receipt = app(DraftGoodsReceipt::class)($this->supplier, $this->warehouse->id, [
            ['product' => $this->product, 'qty' => $qty, 'unit_cost' => $cost],
        ], null, $on ?? $this->today);

        return app(PostGoodsReceipt::class)($receipt);
    }

    private function receiveBatch(Product $product, string $batch, string $expiry, string $qty, string $cost): void
    {
        $receipt = app(DraftGoodsReceipt::class)($this->supplier, $this->warehouse->id, [[
            'product' => $product, 'qty' => $qty, 'unit_cost' => $cost,
            'batch_number' => $batch, 'expiry_date' => new CarbonImmutable($expiry),
        ]], null, $this->today);

        app(PostGoodsReceipt::class)($receipt);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', $role)->value('id'));

        return $user;
    }
}
