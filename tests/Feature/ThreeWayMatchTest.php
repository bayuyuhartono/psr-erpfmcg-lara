<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\CompanySetting;
use App\Domain\Masters\Models\Product;
use App\Domain\Purchasing\Actions\ApprovePurchaseOrder;
use App\Domain\Purchasing\Actions\DraftGoodsReceipt;
use App\Domain\Purchasing\Actions\DraftPurchaseOrder;
use App\Domain\Purchasing\Actions\PostGoodsReceipt;
use App\Domain\Purchasing\Actions\PostSupplierInvoice;
use App\Domain\Purchasing\Actions\SubmitPurchaseOrder;
use App\Domain\Purchasing\Exceptions\MatchVarianceTooLarge;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\SupplierInvoice;
use App\Domain\Purchasing\Models\SupplierInvoiceLine;
use App\Domain\Purchasing\Services\ThreeWayMatch;
use App\Models\User;
use App\Support\Documents\Exceptions\IllegalTransition;
use Brick\Math\BigDecimal;
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

final class ThreeWayMatchTest extends TestCase
{
    use RefreshDatabase;

    private BusinessPartner $supplier;

    private Warehouse $warehouse;

    private Product $product;

    private PurchaseOrder $order;

    private GoodsReceipt $receipt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class,
        ]);
        $this->supplier = BusinessPartner::factory()->create(['is_supplier' => true]);
        $this->warehouse = Warehouse::factory()->create();
        $this->product = Product::factory()->create(['sku' => 'KOPI-01', 'base_uom' => 'PCS']);

        // Ordered 100 at 2,500. Received 100. Both sides agree so far.
        $this->order = $this->approvedOrder('100', '2500');
        $this->receipt = $this->receive('100', '2500');
    }

    public function test_an_invoice_matching_the_order_and_receipt_posts(): void
    {
        $invoice = $this->invoice('100', '2500', subtotal: '250000', vat: '27500');

        app(PostSupplierInvoice::class)($invoice);

        $this->assertSame('posted', $invoice->fresh()->status);

        // Scoped to the invoice's own entry: the receipt also touched 2103,
        // on the other side, which is the whole point of the account.
        $entry = JournalEntry::where('ref_type', 'supplier_invoice')->firstOrFail();
        $sides = $entry->journalLines()->with('account')->get()
            ->mapWithKeys(fn ($line) => [$line->account->code => $line->debit->isPositive() ? 'debit' : 'credit']);

        // The receipt parked 250,000 in GR/IR; the invoice clears it and owes
        // the supplier 277,500 including tax.
        $this->assertSame('debit', $sides['2103']);
        $this->assertSame('debit', $sides['1104']);
        $this->assertSame('credit', $sides['2101']);
        $this->assertSame(
            '277500.00',
            (string) $entry->journalLines()->whereRelation('account', 'code', '2101')->value('credit'),
        );
    }

    public function test_the_grir_account_nets_to_zero_once_both_sides_post(): void
    {
        app(PostSupplierInvoice::class)($this->invoice('100', '2500', subtotal: '250000', vat: '27500'));

        $lines = JournalLine::whereRelation('account', 'code', '2103')->get();

        // Received-not-invoiced is exactly that: it empties when the invoice
        // arrives. A balance left here is goods we have not been billed for.
        $this->assertSame(
            '0.00',
            (string) $lines->reduce(fn ($carry, $line) => $carry->plus($line->debit)->minus($line->credit), BigDecimal::zero())->toScale(2),
        );
    }

    public function test_a_small_difference_inside_tolerance_still_posts(): void
    {
        // 2% of 250,000 is 5,000, which is the binding threshold here.
        $invoice = $this->invoice('100', '2530', subtotal: '253000', vat: '27830');

        $match = app(ThreeWayMatch::class)($invoice);
        $this->assertSame('3000.00', (string) $match->difference());
        $this->assertSame('5000.00', (string) $match->tolerance);
        $this->assertTrue($match->isWithinTolerance());

        app(PostSupplierInvoice::class)($invoice);
        $this->assertSame('posted', $invoice->fresh()->status);
    }

    public function test_being_billed_for_goods_that_never_arrived_is_blocked(): void
    {
        // Received 100, invoiced 130. This is what the match exists for.
        $invoice = $this->invoice('130', '2500', subtotal: '325000', vat: '35750');

        try {
            app(PostSupplierInvoice::class)($invoice);
            $this->fail('Expected the variance to block posting.');
        } catch (MatchVarianceTooLarge $exception) {
            $this->assertStringContainsString('invoiced 130, received 100', $exception->getMessage());
            $this->assertStringContainsString('A supervisor must approve', $exception->getMessage());
            $this->assertSame('draft', $invoice->fresh()->status);
            $this->assertDatabaseCount('journal_entries', 1);   // the receipt's only
        }
    }

    public function test_a_supervisor_can_approve_the_variance_and_it_then_posts(): void
    {
        $invoice = $this->invoice('130', '2500', subtotal: '325000', vat: '35750');
        $finance = $this->userWithRole('Finance Manager');

        app(PostSupplierInvoice::class)($invoice, $finance, approveVariance: true);

        $this->assertSame('posted', $invoice->fresh()->status);
    }

    public function test_someone_without_the_permission_cannot_wave_it_through(): void
    {
        $invoice = $this->invoice('130', '2500', subtotal: '325000', vat: '35750');

        $this->expectException(MatchVarianceTooLarge::class);
        $this->expectExceptionMessageMatches('/may not approve a variance/');

        app(PostSupplierInvoice::class)($invoice, $this->userWithRole('Sales Admin'), approveVariance: true);
    }

    public function test_a_price_above_the_order_is_caught_even_when_the_quantity_agrees(): void
    {
        $invoice = $this->invoice('100', '3000', subtotal: '300000', vat: '33000');

        $match = app(ThreeWayMatch::class)($invoice);

        $this->assertFalse($match->isWithinTolerance());
        $this->assertStringContainsString('price differs from the order', $match->describe());
    }

    public function test_the_tolerance_is_the_stricter_of_the_two_settings(): void
    {
        // On a large invoice the flat amount binds; on a small one the
        // percentage does. Either way the tighter number wins.
        CompanySetting::query()->first()->update([
            'match_tolerance_percent' => '10.00', 'match_tolerance_amount' => '1000.00',
        ]);

        $match = app(ThreeWayMatch::class)($this->invoice('100', '2500', subtotal: '250000', vat: '27500'));

        // 10% of 250,000 is 25,000; the flat 1,000 is stricter.
        $this->assertSame('1000.00', (string) $match->tolerance);
    }

    public function test_an_invoice_posts_once(): void
    {
        $invoice = $this->invoice('100', '2500', subtotal: '250000', vat: '27500');
        app(PostSupplierInvoice::class)($invoice);

        $this->expectException(IllegalTransition::class);
        app(PostSupplierInvoice::class)($invoice->fresh());
    }

    private function invoice(string $qty, string $unitPrice, string $subtotal, string $vat): SupplierInvoice
    {
        $invoice = SupplierInvoice::create([
            'document_number' => 'SI-'.Str::upper(Str::random(6)),
            'supplier_id' => $this->supplier->id,
            'invoice_date' => '2026-08-26',
            'due_date' => '2026-09-25',
            'subtotal' => $subtotal,
            'vat_amount' => $vat,
            'total' => bcadd($subtotal, $vat, 2),
        ]);

        SupplierInvoiceLine::create([
            'supplier_invoice_id' => $invoice->id,
            'goods_receipt_line_id' => $this->receipt->goodsReceiptLines()->value('id'),
            'product_id' => $this->product->id,
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'line_total' => bcmul($qty, $unitPrice, 2),
        ]);

        return $invoice->refresh();
    }

    private function approvedOrder(string $qty, string $price): PurchaseOrder
    {
        $order = app(DraftPurchaseOrder::class)($this->supplier, $this->warehouse->id, [
            ['product' => $this->product, 'qty' => $qty, 'unit_price' => $price],
        ], new CarbonImmutable('2026-08-26'));

        return app(ApprovePurchaseOrder::class)(
            app(SubmitPurchaseOrder::class)($order),
            $this->userWithRole('Purchasing Manager'),
        );
    }

    private function receive(string $qty, string $cost): GoodsReceipt
    {
        $receipt = app(DraftGoodsReceipt::class)($this->supplier, $this->warehouse->id, [[
            'product' => $this->product, 'qty' => $qty, 'unit_cost' => $cost,
            'purchase_order_line_id' => $this->order->purchaseOrderLines()->value('id'),
        ]], $this->order, new CarbonImmutable('2026-08-26'));

        return app(PostGoodsReceipt::class)($receipt);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', $role)->value('id'));

        return $user;
    }
}
