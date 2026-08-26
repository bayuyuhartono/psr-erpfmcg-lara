<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Product;
use App\Domain\Purchasing\Actions\ApprovePurchaseOrder;
use App\Domain\Purchasing\Actions\DraftGoodsReceipt;
use App\Domain\Purchasing\Actions\DraftPurchaseOrder;
use App\Domain\Purchasing\Actions\PostGoodsReceipt;
use App\Domain\Purchasing\Actions\SubmitPurchaseOrder;
use App\Domain\Purchasing\Exceptions\InvalidGoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Documents\Exceptions\DocumentFrozen;
use App\Support\Documents\Exceptions\IllegalTransition;
use App\Support\ShelfLife\Exceptions\ShelfLifeTooShort;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GoodsReceiptTest extends TestCase
{
    use RefreshDatabase;

    private BusinessPartner $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class,
        ]);
        $this->supplier = BusinessPartner::factory()->create(['is_supplier' => true]);
        $this->warehouse = Warehouse::factory()->create();
        $this->product = $this->perishable();
    }

    public function test_receiving_posts_stock_cost_and_the_grir_journal(): void
    {
        $receipt = $this->draft([$this->line('480', '2500', 'B-2608')]);

        app(PostGoodsReceipt::class)($receipt, $this->warehouseUser());

        $this->assertSame('posted', $receipt->fresh()->status);
        $this->assertSame('480.0000', (string) StockBalance::firstOrFail()->qty_base);
        $this->assertSame('2500.0000', (string) ProductCost::firstOrFail()->average_cost);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame('GR/2608/000001', $receipt->document_number);
    }

    public function test_one_ordered_line_can_arrive_as_several_batches(): void
    {
        // F-10: the supplier splits 480 across two production runs.
        $receipt = $this->draft([
            $this->line('300', '2500', 'B-2608-A'),
            $this->line('180', '2500', 'B-2608-B'),
        ]);

        app(PostGoodsReceipt::class)($receipt);

        $this->assertDatabaseCount('stock_batches', 2);
        // Balances are per batch, so the two runs stay distinguishable for FEFO.
        $this->assertSame(2, StockBalance::count());
        $this->assertSame('480.0000', (string) StockBalance::sum('qty_base'));
        $this->assertDatabaseCount('document_postings', 1);
    }

    public function test_short_dated_goods_cannot_be_written_down_as_accepted(): void
    {
        // 100 day shelf life, 70% required. Expiring in 60 days is 60%.
        $product = $this->perishable(['shelf_life_days' => 100, 'min_life_on_receipt_pct' => '70.00']);

        $this->expectException(ShelfLifeTooShort::class);

        $this->draft([[
            'product' => $product, 'qty' => '100', 'unit_cost' => '2500',
            'batch_number' => 'B-SHORT', 'expiry_date' => new CarbonImmutable('2026-10-25'),
        ]]);
    }

    public function test_short_dated_goods_are_recorded_as_turned_away(): void
    {
        $product = $this->perishable(['shelf_life_days' => 100, 'min_life_on_receipt_pct' => '70.00']);

        // The same delivery, recorded honestly: nothing accepted, 100 rejected.
        $receipt = $this->draft([[
            'product' => $product, 'qty' => '0', 'qty_rejected' => '100',
            'rejection_reason' => 'short_shelf_life',
        ]]);

        $line = $receipt->goodsReceiptLines()->firstOrFail();
        $this->assertSame('100.0000', (string) $line->qty_rejected);
        $this->assertSame('short_shelf_life', $line->rejection_reason);
        $this->assertNull($line->batch_id);

        // Nothing to post, so posting refuses rather than writing an empty
        // document.
        $this->expectException(InvalidGoodsReceipt::class);
        $this->expectExceptionMessageMatches('/accepted nothing/');
        app(PostGoodsReceipt::class)($receipt);
    }

    public function test_rejecting_goods_without_saying_why_is_refused(): void
    {
        $this->expectException(InvalidGoodsReceipt::class);
        $this->expectExceptionMessageMatches('/needs a reason/');

        $this->draft([[
            'product' => $this->product, 'qty' => '10', 'unit_cost' => '2500',
            'batch_number' => 'B-1', 'qty_rejected' => '5',
        ]]);
    }

    public function test_a_posted_receipt_cannot_be_edited(): void
    {
        $receipt = $this->draft([$this->line('100', '2500', 'B-1')]);
        app(PostGoodsReceipt::class)($receipt);

        $this->expectException(DocumentFrozen::class);
        $receipt->fresh()->update(['supplier_do_number' => 'DO-CORRECTED']);
    }

    public function test_receiving_advances_the_purchase_order(): void
    {
        $order = $this->approvedOrder('100');
        $line = $order->purchaseOrderLines()->firstOrFail();

        $partial = $this->draft([$this->line('60', '2500', 'B-1') + ['purchase_order_line_id' => $line->id]], $order);
        app(PostGoodsReceipt::class)($partial);

        $this->assertSame('partially_received', $order->fresh()->status);
        $this->assertSame('60.0000', (string) $line->fresh()->qty_received);

        $rest = $this->draft([$this->line('40', '2500', 'B-2') + ['purchase_order_line_id' => $line->id]], $order->fresh());
        app(PostGoodsReceipt::class)($rest);

        $this->assertSame('closed', $order->fresh()->status);
        $this->assertSame('100.0000', (string) $line->fresh()->qty_received);
    }

    public function test_over_delivery_needs_a_reason(): void
    {
        $order = $this->approvedOrder('100');
        $line = $order->purchaseOrderLines()->firstOrFail();

        try {
            $this->draft([$this->line('120', '2500', 'B-1') + ['purchase_order_line_id' => $line->id]], $order);
            $this->fail('Expected the over-delivery to need explaining.');
        } catch (InvalidGoodsReceipt $exception) {
            $this->assertStringContainsString('Over-delivery needs a reason', $exception->getMessage());
            $this->assertStringContainsString('120 of KOPI', $exception->getMessage());
        }

        $receipt = $this->draft([
            $this->line('120', '2500', 'B-1') + [
                'purchase_order_line_id' => $line->id,
                'variance_reason' => 'Supplier shipped a full pallet; agreed with buyer',
            ],
        ], $order);

        $this->assertSame(
            'Supplier shipped a full pallet; agreed with buyer',
            $receipt->goodsReceiptLines()->value('variance_reason'),
        );
    }

    public function test_a_plain_partial_delivery_needs_no_explanation(): void
    {
        // The rest is still coming. That is what partially received means.
        $order = $this->approvedOrder('100');
        $line = $order->purchaseOrderLines()->firstOrFail();

        $receipt = $this->draft([$this->line('60', '2500', 'B-1') + ['purchase_order_line_id' => $line->id]], $order);
        app(PostGoodsReceipt::class)($receipt);

        $this->assertNull($receipt->goodsReceiptLines()->value('variance_reason'));
        $this->assertSame('partially_received', $order->fresh()->status);
        $this->assertFalse($line->fresh()->closed_short);
    }

    public function test_closing_a_line_short_needs_a_reason_and_then_closes_the_order(): void
    {
        $order = $this->approvedOrder('100');
        $line = $order->purchaseOrderLines()->firstOrFail();

        try {
            $this->draft([
                $this->line('60', '2500', 'B-1') + ['purchase_order_line_id' => $line->id, 'close_line' => true],
            ], $order);
            $this->fail('Expected closing short to need a reason.');
        } catch (InvalidGoodsReceipt $exception) {
            $this->assertStringContainsString('short at 60 of 100', $exception->getMessage());
        }

        $receipt = $this->draft([
            $this->line('60', '2500', 'B-1') + [
                'purchase_order_line_id' => $line->id,
                'close_line' => true,
                'variance_reason' => 'Supplier discontinued the SKU',
            ],
        ], $order);
        app(PostGoodsReceipt::class)($receipt);

        // 60 of 100 received, but nobody is waiting for the other 40.
        $this->assertTrue($line->fresh()->closed_short);
        $this->assertSame('60.0000', (string) $line->fresh()->qty_received);
        $this->assertSame('closed', $order->fresh()->status);
    }

    public function test_goods_cannot_be_received_against_an_unapproved_order(): void
    {
        $order = app(SubmitPurchaseOrder::class)($this->order('100'));

        $this->expectException(InvalidGoodsReceipt::class);
        $this->expectExceptionMessageMatches('/only be received against an approved order/');

        $this->draft([$this->line('100', '2500', 'B-1')], $order);
    }

    public function test_a_receipt_is_posted_once_however_many_times_it_is_clicked(): void
    {
        $receipt = $this->draft([$this->line('100', '2500', 'B-1')]);
        app(PostGoodsReceipt::class)($receipt);

        // The state machine stops the ordinary second click, and says so in
        // terms of the document rather than the posting reference.
        try {
            app(PostGoodsReceipt::class)($receipt->fresh());
            $this->fail('Expected the second posting to be refused.');
        } catch (IllegalTransition $exception) {
            $this->assertStringContainsString('"posted" to "posted"', $exception->getMessage());
        }

        $this->assertDatabaseCount('stock_ledger', 1);
        $this->assertDatabaseCount('document_postings', 1);
    }

    /** @param  array<string, mixed>  $attributes */
    private function perishable(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + [
            'sku' => 'KOPI-'.fake()->unique()->numberBetween(1, 9999),
            'base_uom' => 'PCS', 'track_batch' => true, 'track_expiry' => true, 'shelf_life_days' => 365,
        ]);
    }

    /** @return array<string, mixed> */
    private function line(string $qty, string $cost, string $batch): array
    {
        return [
            'product' => $this->product, 'qty' => $qty, 'unit_cost' => $cost,
            'batch_number' => $batch, 'production_date' => new CarbonImmutable('2026-08-01'),
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function draft(array $lines, ?PurchaseOrder $order = null): GoodsReceipt
    {
        return app(DraftGoodsReceipt::class)(
            $this->supplier, $this->warehouse->id, $lines, $order, new CarbonImmutable('2026-08-26'),
        );
    }

    private function order(string $qty): PurchaseOrder
    {
        return app(DraftPurchaseOrder::class)($this->supplier, $this->warehouse->id, [
            ['product' => $this->product, 'qty' => $qty, 'unit_price' => '2500'],
        ], new CarbonImmutable('2026-08-26'));
    }

    private function approvedOrder(string $qty): PurchaseOrder
    {
        $order = app(SubmitPurchaseOrder::class)($this->order($qty));

        return app(ApprovePurchaseOrder::class)($order, $this->userWithRole('Purchasing Manager'));
    }

    private function warehouseUser(): User
    {
        return $this->userWithRole('Warehouse Supervisor');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', $role)->value('id'));

        return $user;
    }
}
