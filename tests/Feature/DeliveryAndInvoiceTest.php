<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseLocation;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\CustomerTier;
use App\Domain\Masters\Models\PriceList;
use App\Domain\Masters\Models\PriceListItem;
use App\Domain\Masters\Models\Product;
use App\Domain\Purchasing\Actions\DraftGoodsReceipt;
use App\Domain\Purchasing\Actions\PostGoodsReceipt;
use App\Domain\Sales\Actions\DraftSalesOrder;
use App\Domain\Sales\Actions\InvoiceDelivery;
use App\Domain\Sales\Actions\PlanDelivery;
use App\Domain\Sales\Actions\PostSalesReturn;
use App\Domain\Sales\Actions\RecordProofOfDelivery;
use App\Domain\Sales\Actions\ShipDelivery;
use App\Domain\Sales\Actions\SubmitSalesOrder;
use App\Domain\Sales\Exceptions\InvalidDelivery;
use App\Domain\Sales\Exceptions\InvalidSalesInvoice;
use App\Domain\Sales\Exceptions\InvalidSalesReturn;
use App\Domain\Sales\Models\DeliveryLine;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Models\SalesReturn;
use App\Domain\Sales\Models\SalesReturnLine;
use App\Domain\Sales\Services\PickingList;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeliveryAndInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private BusinessPartner $customer;

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
        $this->today = new CarbonImmutable('2026-08-26');
        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
        $this->supplier = BusinessPartner::factory()->create(['is_supplier' => true]);
        $this->product = Product::factory()->create(['sku' => 'KOPI-01', 'base_uom' => 'PCS', 'vat_percent' => '11.00']);

        $list = PriceList::create(['name' => 'Wholesale', 'active' => true]);
        PriceListItem::create([
            'price_list_id' => $list->id, 'product_id' => $this->product->id,
            'uom' => 'PCS', 'min_qty' => '0', 'unit_price' => '5000',
        ]);
        $tier = CustomerTier::create(['name' => 'Wholesale', 'price_list_id' => $list->id]);
        $this->customer = BusinessPartner::factory()->create([
            'tier_id' => $tier->id, 'credit_limit' => '50000000', 'payment_terms_days' => 30,
        ]);

        $this->receive('500', '3000');
    }

    public function test_one_delivery_covers_several_orders_for_the_same_customer(): void
    {
        $first = $this->approvedOrder('100');
        $second = $this->approvedOrder('40');

        $delivery = app(PlanDelivery::class)([$first, $second], $this->today);

        $this->assertSame('picking', $delivery->status);
        $this->assertSame(2, $delivery->salesOrders()->count());
        $this->assertSame('140.0000', (string) $delivery->deliveryLines()->sum('qty_base'));
    }

    public function test_two_customers_cannot_share_one_trip(): void
    {
        $other = BusinessPartner::factory()->create(['tier_id' => $this->customer->tier_id, 'credit_limit' => '1000000']);
        $mine = $this->approvedOrder('10');
        $theirs = app(SubmitSalesOrder::class)(app(DraftSalesOrder::class)(
            $other, $this->warehouse->id, [['product' => $this->product, 'qty' => '10']], $this->today,
        ));

        $this->expectException(InvalidDelivery::class);
        $this->expectExceptionMessageMatches('/one customer/');

        app(PlanDelivery::class)([$mine, $theirs], $this->today);
    }

    public function test_the_picking_list_follows_the_rack_walk(): void
    {
        $far = WarehouseLocation::create(['warehouse_id' => $this->warehouse->id, 'code' => 'Z-09', 'pick_sequence' => 90]);
        $near = WarehouseLocation::create(['warehouse_id' => $this->warehouse->id, 'code' => 'A-01', 'pick_sequence' => 10]);

        $delivery = app(PlanDelivery::class)([$this->approvedOrder('20')], $this->today);
        $lines = $delivery->deliveryLines()->get();
        $lines[0]->update(['location_id' => $far->id]);

        $extra = Product::factory()->create(['sku' => 'TEH-01', 'base_uom' => 'PCS']);
        DeliveryLine::create([
            'delivery_id' => $delivery->id, 'product_id' => $extra->id,
            'location_id' => $near->id, 'qty_base' => '5',
        ]);

        // A third bay with no sequence at all, which must sort last however
        // the rows happen to come back from the database.
        $unsequenced = WarehouseLocation::create(['warehouse_id' => $this->warehouse->id, 'code' => 'B-05']);
        $third = Product::factory()->create(['sku' => 'GULA-01', 'base_uom' => 'PCS']);
        DeliveryLine::create([
            'delivery_id' => $delivery->id, 'product_id' => $third->id,
            'location_id' => $unsequenced->id, 'qty_base' => '2',
        ]);

        $list = app(PickingList::class)->for($delivery->fresh());

        $this->assertSame(['A-01', 'Z-09', 'B-05'], array_column($list, 'location'));
    }

    public function test_shipping_recognises_cost_but_not_yet_revenue(): void
    {
        $order = $this->approvedOrder('100');
        $delivery = app(PlanDelivery::class)([$order], $this->today);

        app(ShipDelivery::class)($delivery);

        $this->assertSame('in_transit', $delivery->fresh()->status);
        $this->assertSame('400.0000', (string) StockBalance::firstOrFail()->qty_base);
        // Reservations are consumed: the goods have gone, so holding them too
        // would double-count.
        $this->assertSame('consumed', StockReservation::firstOrFail()->status);
        // The whole order went, so it closes. Nothing set that by hand.
        $this->assertSame('closed', $order->fresh()->status);

        $accounts = JournalEntry::where('ref_type', 'delivery')->firstOrFail()
            ->journalLines()->with('account')->get()
            ->mapWithKeys(fn ($l) => [$l->account->code => $l->debit->isPositive() ? 'debit' : 'credit']);

        $this->assertSame('debit', $accounts['5101']);
        $this->assertSame('credit', $accounts['1103']);
        // No revenue yet: a load that comes back is not a sale.
        $this->assertSame(0, JournalEntry::where('ref_type', 'sales_invoice')->count());
    }

    public function test_an_invoice_bills_what_the_outlet_took_not_what_was_loaded(): void
    {
        $order = $this->approvedOrder('100');
        $delivery = app(PlanDelivery::class)([$order], $this->today);
        app(ShipDelivery::class)($delivery);

        $line = $delivery->deliveryLines()->firstOrFail();
        app(RecordProofOfDelivery::class)($delivery->fresh(), [$line->id => '90'], 'Ibu Sari');

        $invoice = app(InvoiceDelivery::class)($delivery->fresh());

        $this->assertSame('90.0000', (string) $invoice->salesInvoiceLines()->value('qty'));
        $this->assertSame('450000.00', (string) $invoice->subtotal);
        $this->assertSame('49500.00', (string) $invoice->vat_amount);
        $this->assertSame('499500.00', (string) $invoice->total);
        $this->assertSame('2026-09-25', $invoice->due_date->format('Y-m-d'));

        $accounts = JournalEntry::where('ref_type', 'sales_invoice')->firstOrFail()
            ->journalLines()->with('account')->get()
            ->mapWithKeys(fn ($l) => [$l->account->code => $l->debit->isPositive() ? 'debit' : 'credit']);

        $this->assertSame('debit', $accounts['1102']);
        $this->assertSame('credit', $accounts['4101']);
        $this->assertSame('credit', $accounts['2102']);
    }

    public function test_a_shortfall_raises_a_return_for_the_goods_coming_back(): void
    {
        $order = $this->approvedOrder('100');
        $delivery = app(PlanDelivery::class)([$order], $this->today);
        app(ShipDelivery::class)($delivery);
        $line = $delivery->deliveryLines()->firstOrFail();

        $pod = app(RecordProofOfDelivery::class)($delivery->fresh(), [$line->id => '90'], 'Ibu Sari');

        $this->assertSame('partial', $pod->outcome);
        $this->assertSame('delivered', $delivery->fresh()->status);

        $return = SalesReturn::firstOrFail();
        $this->assertSame('draft', $return->status);
        $this->assertSame('10.0000', (string) $return->salesReturnLines()->value('qty_base'));
    }

    public function test_a_refused_load_fails_the_delivery_and_bills_nothing(): void
    {
        $order = $this->approvedOrder('50');
        $delivery = app(PlanDelivery::class)([$order], $this->today);
        app(ShipDelivery::class)($delivery);
        $line = $delivery->deliveryLines()->firstOrFail();

        $pod = app(RecordProofOfDelivery::class)($delivery->fresh(), [$line->id => '0']);

        $this->assertSame('refused', $pod->outcome);
        $this->assertSame('failed', $delivery->fresh()->status);

        $this->expectException(InvalidSalesInvoice::class);
        app(InvoiceDelivery::class)($delivery->fresh());
    }

    public function test_an_invoice_cannot_run_ahead_of_the_proof_of_delivery(): void
    {
        $delivery = app(PlanDelivery::class)([$this->approvedOrder('10')], $this->today);
        app(ShipDelivery::class)($delivery);

        $this->expectException(InvalidSalesInvoice::class);
        $this->expectExceptionMessageMatches('/not the other way round/');

        app(InvoiceDelivery::class)($delivery->fresh());
    }

    public function test_resellable_goods_go_back_on_the_shelf(): void
    {
        $return = $this->returnGoods($this->warehouse, resellable: true);

        app(PostSalesReturn::class)($return);

        $this->assertSame('510.0000', (string) StockBalance::where('warehouse_id', $this->warehouse->id)->value('qty_base'));
        $this->assertSame('3000.0000', (string) ProductCost::firstOrFail()->average_cost);
    }

    public function test_unsellable_goods_are_refused_a_normal_warehouse(): void
    {
        $return = $this->returnGoods($this->warehouse, resellable: false);

        $this->expectException(InvalidSalesReturn::class);
        $this->expectExceptionMessageMatches('/go to a damaged warehouse/');

        app(PostSalesReturn::class)($return);
    }

    public function test_unsellable_goods_stay_on_the_books_in_the_damaged_warehouse(): void
    {
        $damaged = Warehouse::factory()->create(['code' => 'WH-DMG', 'warehouse_type' => 'damaged']);
        $return = $this->returnGoods($damaged, resellable: false);

        app(PostSalesReturn::class)($return);

        // Countable and valued, but nothing there is ever picked. The goods
        // are back on the books, so the total rises by what came back.
        $this->assertSame('10.0000', (string) StockBalance::where('warehouse_id', $damaged->id)->value('qty_base'));
        $this->assertSame('510.0000', (string) ProductCost::firstOrFail()->total_qty);
        $this->assertSame('1530000.00', (string) ProductCost::firstOrFail()->total_value);
    }

    private function returnGoods(Warehouse $warehouse, bool $resellable): SalesReturn
    {
        $return = SalesReturn::create([
            'document_number' => 'SR/2608/'.fake()->unique()->numerify('######'),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $warehouse->id,
            'return_date' => $this->today->format('Y-m-d'),
            'reason' => $resellable ? 'unsold' : 'damaged',
            'resellable' => $resellable,
        ]);

        SalesReturnLine::create([
            'sales_return_id' => $return->id, 'product_id' => $this->product->id,
            'qty_base' => '10', 'unit_price' => '5000',
        ]);

        return $return->refresh();
    }

    private function approvedOrder(string $qty): SalesOrder
    {
        return app(SubmitSalesOrder::class)(app(DraftSalesOrder::class)(
            $this->customer->fresh(), $this->warehouse->id,
            [['product' => $this->product, 'qty' => $qty]], $this->today,
        ));
    }

    private function receive(string $qty, string $cost): void
    {
        $receipt = app(DraftGoodsReceipt::class)($this->supplier, $this->warehouse->id, [
            ['product' => $this->product, 'qty' => $qty, 'unit_cost' => $cost],
        ], null, $this->today);

        app(PostGoodsReceipt::class)($receipt);
    }
}
