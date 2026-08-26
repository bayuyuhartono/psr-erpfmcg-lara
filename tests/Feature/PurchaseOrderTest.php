<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Actions\AddProductUom;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Product;
use App\Domain\Purchasing\Actions\ApprovePurchaseOrder;
use App\Domain\Purchasing\Actions\DraftPurchaseOrder;
use App\Domain\Purchasing\Actions\SubmitPurchaseOrder;
use App\Domain\Purchasing\Exceptions\ApprovalLimitExceeded;
use App\Domain\Purchasing\Exceptions\InvalidPurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Documents\Exceptions\DocumentFrozen;
use App\Support\Documents\Exceptions\IllegalTransition;
use App\Support\Documents\Exceptions\TransitionNotPermitted;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private BusinessPartner $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class, NumberSequencesSeeder::class]);
        $this->supplier = BusinessPartner::factory()->create(['is_supplier' => true, 'payment_terms_days' => 30]);
        $this->warehouse = Warehouse::factory()->create();
        $this->product = Product::factory()->create(['sku' => 'KOPI-01', 'base_uom' => 'PCS', 'vat_percent' => '11.00']);
        app(AddProductUom::class)($this->product, 'CARTON', '24', defaultForPurchase: true);
        $this->product->refresh();
    }

    public function test_a_line_snapshots_the_unit_it_was_ordered_in(): void
    {
        $order = $this->draft([['product' => $this->product, 'uom' => 'CARTON', 'qty' => '10', 'unit_price' => '48000']]);
        $line = $order->purchaseOrderLines()->firstOrFail();

        $this->assertSame('CARTON', $line->uom);
        $this->assertSame('24.0000', (string) $line->qty_in_base);
        $this->assertSame('240.0000', (string) $line->qty_base);
        $this->assertSame('480000.00', (string) $line->line_total);

        // Correcting the carton next month leaves this order meaning 240 pieces.
        app(AddProductUom::class)($this->product, 'CARTON', '20');
        $this->assertSame('240.0000', (string) $line->fresh()->qty_base);
    }

    public function test_the_default_purchase_unit_is_used_when_none_is_given(): void
    {
        $order = $this->draft([['product' => $this->product, 'qty' => '5', 'unit_price' => '48000']]);

        $this->assertSame('CARTON', $order->purchaseOrderLines()->value('uom'));
    }

    public function test_the_header_totals_are_computed_from_the_lines(): void
    {
        $order = $this->draft([
            ['product' => $this->product, 'uom' => 'CARTON', 'qty' => '10', 'unit_price' => '48000', 'discount_percent' => '5'],
        ]);

        // 480,000 gross, 5% off is 24,000, VAT 11% on the 456,000 net.
        $this->assertSame('480000.00', (string) $order->subtotal);
        $this->assertSame('24000.00', (string) $order->discount_amount);
        $this->assertSame('50160.00', (string) $order->vat_amount);
        $this->assertSame('506160.00', (string) $order->total);
    }

    public function test_the_document_number_comes_from_the_sequence(): void
    {
        $first = $this->draft([['product' => $this->product, 'qty' => '1', 'unit_price' => '1000']]);
        $second = $this->draft([['product' => $this->product, 'qty' => '1', 'unit_price' => '1000']]);

        $this->assertSame('PO/2608/000001', $first->document_number);
        $this->assertSame('PO/2608/000002', $second->document_number);
    }

    public function test_an_order_cannot_be_raised_on_a_partner_who_is_not_a_supplier(): void
    {
        $customer = BusinessPartner::factory()->create(['is_supplier' => false, 'is_customer' => true]);

        $this->expectException(InvalidPurchaseOrder::class);
        $this->expectExceptionMessageMatches('/not a supplier/');

        app(DraftPurchaseOrder::class)($customer, $this->warehouse->id, [
            ['product' => $this->product, 'qty' => '1', 'unit_price' => '1000'],
        ], new CarbonImmutable('2026-08-26'));
    }

    public function test_a_submitted_order_stops_being_editable(): void
    {
        $order = $this->draft([['product' => $this->product, 'qty' => '1', 'unit_price' => '1000']]);

        // Free to change while it is a draft.
        $order->update(['note' => 'Call the supplier first']);

        app(SubmitPurchaseOrder::class)($order);

        $this->expectException(DocumentFrozen::class);
        $order->fresh()->update(['note' => 'Actually, more urgent']);
    }

    public function test_approval_is_refused_to_someone_without_the_permission(): void
    {
        $order = $this->submitted('1000');

        try {
            app(ApprovePurchaseOrder::class)($order, $this->userWithRole('Sales Admin'));
            $this->fail('Expected approval to be refused.');
        } catch (TransitionNotPermitted) {
            $this->assertSame('pending_approval', $order->fresh()->status);
        }
    }

    public function test_a_buyer_approves_within_their_ceiling(): void
    {
        $order = $this->submitted('1000000');
        $buyer = $this->userWithRole('Purchasing');

        app(ApprovePurchaseOrder::class)($order, $buyer);

        $this->assertSame('approved', $order->fresh()->status);
        $this->assertSame($buyer->id, $order->fresh()->approved_by);
        $this->assertNotNull($order->fresh()->approved_at);
    }

    public function test_a_buyer_cannot_approve_above_their_ceiling(): void
    {
        // 2,000 cartons at 48,000 clears 50 million once VAT is added.
        $order = $this->submitted('48000', qty: '2000');

        try {
            app(ApprovePurchaseOrder::class)($order, $this->userWithRole('Purchasing'));
            $this->fail('Expected the approval ceiling to bite.');
        } catch (ApprovalLimitExceeded $exception) {
            $this->assertStringContainsString('above what', $exception->getMessage());
            $this->assertSame('pending_approval', $order->fresh()->status);
        }

        // The manager has no ceiling.
        app(ApprovePurchaseOrder::class)($order->fresh(), $this->userWithRole('Purchasing Manager'));
        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_an_order_cannot_jump_straight_from_draft_to_approved(): void
    {
        $order = $this->draft([['product' => $this->product, 'qty' => '1', 'unit_price' => '1000']]);

        $this->expectException(IllegalTransition::class);

        app(ApprovePurchaseOrder::class)($order, $this->userWithRole('Purchasing Manager'));
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function draft(array $lines): PurchaseOrder
    {
        return app(DraftPurchaseOrder::class)(
            $this->supplier,
            $this->warehouse->id,
            $lines,
            new CarbonImmutable('2026-08-26'),
        );
    }

    private function submitted(string $unitPrice, string $qty = '1'): PurchaseOrder
    {
        $order = $this->draft([
            ['product' => $this->product, 'uom' => 'CARTON', 'qty' => $qty, 'unit_price' => $unitPrice],
        ]);

        return app(SubmitPurchaseOrder::class)($order);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', $role)->value('id'));

        return $user;
    }
}
