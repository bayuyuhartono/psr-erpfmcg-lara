<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Actions\AddProductUom;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\CustomerTier;
use App\Domain\Masters\Models\DiscountRule;
use App\Domain\Masters\Models\PriceList;
use App\Domain\Masters\Models\PriceListItem;
use App\Domain\Masters\Models\Product;
use App\Domain\Sales\Actions\CopyPreviousOrder;
use App\Domain\Sales\Actions\DraftSalesOrder;
use App\Domain\Sales\Actions\ReleaseCreditHold;
use App\Domain\Sales\Actions\SubmitSalesOrder;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Services\CreditCheck;
use App\Models\User;
use App\Support\Documents\Exceptions\TransitionNotPermitted;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SalesOrderTest extends TestCase
{
    use RefreshDatabase;

    private BusinessPartner $customer;

    private Warehouse $warehouse;

    private Product $product;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class, NumberSequencesSeeder::class]);
        $this->today = new CarbonImmutable('2026-08-26');
        $this->warehouse = Warehouse::factory()->create();
        $this->product = Product::factory()->create(['sku' => 'KOPI-01', 'base_uom' => 'PCS', 'vat_percent' => '11.00']);
        app(AddProductUom::class)($this->product, 'CARTON', '24', defaultForSale: true);
        $this->product->refresh();

        $list = PriceList::create(['name' => 'Wholesale', 'active' => true]);
        PriceListItem::create([
            'price_list_id' => $list->id, 'product_id' => $this->product->id,
            'uom' => 'CARTON', 'min_qty' => '0', 'unit_price' => '100000',
        ]);
        $tier = CustomerTier::create(['name' => 'Wholesale', 'price_list_id' => $list->id]);
        $this->customer = BusinessPartner::factory()->create([
            'tier_id' => $tier->id, 'credit_limit' => '10000000', 'payment_terms_days' => 30,
        ]);
    }

    public function test_a_line_is_priced_from_the_tier_and_keeps_its_discount_tiers_apart(): void
    {
        DiscountRule::create(['name' => 'Promo', 'scope' => 'all', 'target' => 'all', 'percent' => '5.00', 'sequence' => 1]);
        DiscountRule::create(['name' => 'Volume', 'scope' => 'all', 'target' => 'all', 'percent' => '3.00', 'sequence' => 2]);

        $order = $this->draft([['product' => $this->product, 'uom' => 'CARTON', 'qty' => '10']]);
        $line = $order->salesOrderLines()->firstOrFail();

        $this->assertSame('100000.00', (string) $line->unit_price);
        $this->assertSame('5.00', (string) $line->discount1_percent);
        $this->assertSame('3.00', (string) $line->discount2_percent);
        // 5 then 3 leaves 92.15%, not 92%.
        $this->assertSame('921500.00', (string) $line->line_total);
        $this->assertSame('240.0000', (string) $line->qty_base);
    }

    public function test_bonus_goods_carry_no_revenue_but_still_leave_the_warehouse(): void
    {
        $order = $this->draft([
            ['product' => $this->product, 'uom' => 'CARTON', 'qty' => '10'],
            ['product' => $this->product, 'uom' => 'CARTON', 'qty' => '1', 'is_bonus' => true],
        ]);

        $bonus = $order->salesOrderLines()->where('is_bonus', true)->firstOrFail();

        $this->assertSame('0.00', (string) $bonus->line_total);
        // The quantity is real: 24 pieces go out and carry their cost with them.
        $this->assertSame('24.0000', (string) $bonus->qty_base);
        $this->assertSame('1000000.00', (string) $order->subtotal);
    }

    public function test_an_order_within_the_limit_is_approved_and_reserves_its_stock(): void
    {
        $order = app(SubmitSalesOrder::class)($this->draft([
            ['product' => $this->product, 'uom' => 'CARTON', 'qty' => '10'],
        ]));

        $this->assertSame('approved', $order->status);
        $this->assertSame('240.0000', (string) StockReservation::firstOrFail()->qty_base);
        $this->assertSame('active', StockReservation::firstOrFail()->status);
        $this->assertNotNull(StockReservation::firstOrFail()->expires_at);
    }

    public function test_an_order_over_the_limit_is_held_with_the_numbers_shown(): void
    {
        $this->customer->update(['credit_limit' => '500000']);

        $order = app(SubmitSalesOrder::class)($this->draft([
            ['product' => $this->product, 'uom' => 'CARTON', 'qty' => '10'],
        ]));

        $this->assertSame('credit_hold', $order->status);
        $this->assertSame('over_credit_limit', $order->hold_reason);
        $this->assertStringContainsString('over by', $order->hold_detail);
        // Held, not approved — so nothing is reserved.
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_an_overdue_invoice_holds_the_order_even_inside_the_limit(): void
    {
        $this->openInvoice('1000000', dueOn: '2026-07-01');

        $order = app(SubmitSalesOrder::class)($this->draft([
            ['product' => $this->product, 'uom' => 'CARTON', 'qty' => '1'],
        ]));

        $this->assertSame('overdue_invoices', $order->hold_reason);
        $this->assertStringContainsString('1 invoice(s) overdue', $order->hold_detail);
    }

    public function test_a_blocked_customer_is_held_whatever_the_numbers_say(): void
    {
        $this->customer->update(['on_hold' => true, 'hold_reason' => 'Cheques bouncing']);

        $order = app(SubmitSalesOrder::class)($this->draft([
            ['product' => $this->product, 'uom' => 'CARTON', 'qty' => '1'],
        ]));

        $this->assertSame('customer_blocked', $order->hold_reason);
        $this->assertStringContainsString('Cheques bouncing', $order->hold_detail);
    }

    public function test_releasing_a_hold_needs_the_permission_and_records_who(): void
    {
        $this->customer->update(['credit_limit' => '500000']);
        $order = app(SubmitSalesOrder::class)($this->draft([
            ['product' => $this->product, 'uom' => 'CARTON', 'qty' => '10'],
        ]));

        try {
            app(ReleaseCreditHold::class)($order, $this->userWithRole('Sales Admin'));
            $this->fail('Expected releasing a hold to be permissioned.');
        } catch (TransitionNotPermitted) {
            $this->assertSame('credit_hold', $order->fresh()->status);
        }

        $supervisor = $this->userWithRole('Sales Supervisor');
        app(ReleaseCreditHold::class)($order->fresh(), $supervisor, 'Owner approved by phone');

        $this->assertSame('approved', $order->fresh()->status);
        $this->assertSame($supervisor->id, $order->fresh()->released_by);
        $this->assertNotNull($order->fresh()->released_at);
        // Releasing reserves the stock the hold was withholding.
        $this->assertDatabaseCount('stock_reservations', 1);
    }

    public function test_a_paid_invoice_does_not_count_against_the_limit(): void
    {
        $invoice = $this->openInvoice('9000000', dueOn: '2026-09-30');
        $status = app(CreditCheck::class)($this->customer->fresh(), $this->today);
        $this->assertSame('9000000.00', (string) $status->outstanding);

        $invoice->transitionTo('paid', attributes: ['paid_amount' => '9000000']);

        $this->assertSame('0.00', (string) app(CreditCheck::class)($this->customer->fresh(), $this->today)->outstanding);
    }

    public function test_copying_the_last_order_reprices_it_at_todays_list(): void
    {
        $first = $this->draft([['product' => $this->product, 'uom' => 'CARTON', 'qty' => '10']]);
        $this->assertSame('1000000.00', (string) $first->subtotal);

        // The price list moves before the customer reorders.
        PriceListItem::query()->update(['unit_price' => '110000']);

        $copy = app(CopyPreviousOrder::class)($this->customer->fresh(), $this->warehouse->id, orderDate: $this->today);

        $this->assertSame('10.0000', (string) $copy->salesOrderLines()->value('qty'));
        // Quantities carry over; last month's price does not.
        $this->assertSame('1100000.00', (string) $copy->subtotal);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function draft(array $lines): SalesOrder
    {
        return app(DraftSalesOrder::class)($this->customer->fresh(), $this->warehouse->id, $lines, $this->today);
    }

    private function openInvoice(string $total, string $dueOn): SalesInvoice
    {
        return SalesInvoice::create([
            'document_number' => 'INV-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-06-01',
            'due_date' => $dueOn,
            'subtotal' => $total, 'total' => $total, 'status' => 'posted',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', $role)->value('id'));

        return $user;
    }
}
