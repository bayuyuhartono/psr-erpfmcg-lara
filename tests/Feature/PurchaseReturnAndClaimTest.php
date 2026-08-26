<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Product;
use App\Domain\Purchasing\Actions\DraftGoodsReceipt;
use App\Domain\Purchasing\Actions\PostGoodsReceipt;
use App\Domain\Purchasing\Actions\PostPurchaseReturn;
use App\Domain\Purchasing\Actions\RaisePrincipalClaim;
use App\Domain\Purchasing\Exceptions\InvalidPrincipalClaim;
use App\Domain\Purchasing\Models\PrincipalClaim;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\PurchaseReturnLine;
use App\Support\Documents\Exceptions\DocumentFrozen;
use App\Support\Documents\Exceptions\IllegalTransition;
use App\Support\Posting\Exceptions\InsufficientStock;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PurchaseReturnAndClaimTest extends TestCase
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
        $this->supplier = BusinessPartner::factory()->create(['is_supplier' => true, 'is_principal' => true]);
        $this->warehouse = Warehouse::factory()->create();
        $this->product = Product::factory()->create(['sku' => 'KOPI-01', 'base_uom' => 'PCS']);
        $this->receive('100', '2500');
    }

    public function test_a_return_takes_stock_out_and_reduces_what_we_owe(): void
    {
        $return = $this->draftReturn('20');

        app(PostPurchaseReturn::class)($return);

        $this->assertSame('posted', $return->fresh()->status);
        $this->assertSame('80.0000', (string) StockBalance::firstOrFail()->qty_base);
        $this->assertSame('200000.00', (string) ProductCost::firstOrFail()->total_value);

        $entry = JournalEntry::where('ref_type', 'purchase_return')->firstOrFail();
        $sides = $entry->journalLines()->with('account')->get()
            ->mapWithKeys(fn ($line) => [$line->account->code => $line->debit->isPositive() ? 'debit' : 'credit']);

        // Goods go back, the payable goes down.
        $this->assertSame('debit', $sides['2101']);
        $this->assertSame('credit', $sides['1103']);
        $this->assertSame('50000.00', (string) $entry->journalLines()->whereRelation('account', 'code', '2101')->value('debit'));
    }

    public function test_stock_leaves_at_what_it_is_carried_at(): void
    {
        // A second receipt moves the average to 3,000 before the return.
        $this->receive('100', '3500');
        $this->assertSame('3000.0000', (string) ProductCost::firstOrFail()->average_cost);

        app(PostPurchaseReturn::class)($this->draftReturn('20'));

        // 20 out at the carrying value, not at what the first delivery cost.
        $this->assertSame('180.0000', (string) ProductCost::firstOrFail()->total_qty);
        $this->assertSame('540000.00', (string) ProductCost::firstOrFail()->total_value);
    }

    public function test_more_cannot_be_returned_than_is_held(): void
    {
        $this->expectException(InsufficientStock::class);

        app(PostPurchaseReturn::class)($this->draftReturn('120'));
    }

    public function test_a_posted_return_is_frozen_and_posts_once(): void
    {
        $return = $this->draftReturn('10');
        app(PostPurchaseReturn::class)($return);

        try {
            $return->fresh()->update(['reason' => 'changed my mind']);
            $this->fail('Expected a posted return to be frozen.');
        } catch (DocumentFrozen) {
            $this->assertDatabaseCount('stock_ledger', 2);
        }

        $this->expectException(IllegalTransition::class);
        app(PostPurchaseReturn::class)($return->fresh());
    }

    public function test_a_claim_totals_its_lines_and_moves_no_stock(): void
    {
        $before = StockBalance::firstOrFail()->qty_base;

        $claim = app(RaisePrincipalClaim::class)($this->supplier, 'expired', [
            ['product_id' => $this->product->id, 'qty_base' => '12', 'unit_price' => '2500'],
            ['product_id' => $this->product->id, 'qty_base' => '8', 'unit_price' => '2500'],
        ], new CarbonImmutable('2026-08-26'));

        $this->assertSame('50000.00', (string) $claim->total_amount);
        $this->assertSame('CLM/2608/000001', $claim->document_number);
        $this->assertSame('draft', $claim->status);

        // Claiming and writing off are separate decisions: goods can sit on the
        // shelf awaiting collection while the claim is open.
        $this->assertSame((string) $before, (string) StockBalance::firstOrFail()->qty_base);
        $this->assertDatabaseCount('stock_ledger', 1);
    }

    public function test_a_claim_follows_what_the_principal_decided(): void
    {
        $claim = $this->claim();

        $claim->transitionTo('submitted');
        $claim->transitionTo('rejected');

        // Rejected is not the end: it goes back to draft to be argued again.
        $claim->transitionTo('draft');
        $claim->transitionTo('submitted');
        $claim->transitionTo('approved', null, ['approved_amount' => '18000.00']);

        $this->assertSame('18000.00', (string) $claim->fresh()->approved_amount);

        $claim->transitionTo('settled');

        $this->expectException(DocumentFrozen::class);
        $claim->fresh()->update(['claim_type' => 'damaged']);
    }

    public function test_a_claim_cannot_skip_straight_to_approved(): void
    {
        $this->expectException(IllegalTransition::class);

        $this->claim()->transitionTo('approved');
    }

    public function test_a_claim_type_outside_the_three_is_refused(): void
    {
        $this->expectException(InvalidPrincipalClaim::class);
        $this->expectExceptionMessageMatches('/not one of expired, damaged, recall/');

        app(RaisePrincipalClaim::class)($this->supplier, 'shrinkage', [
            ['product_id' => $this->product->id, 'qty_base' => '1', 'unit_price' => '1'],
        ]);
    }

    private function claim(): PrincipalClaim
    {
        return app(RaisePrincipalClaim::class)($this->supplier, 'expired', [
            ['product_id' => $this->product->id, 'qty_base' => '10', 'unit_price' => '2500'],
        ], new CarbonImmutable('2026-08-26'));
    }

    private function draftReturn(string $qty): PurchaseReturn
    {
        $return = PurchaseReturn::create([
            'document_number' => 'PR-'.Str::upper(Str::random(6)),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'return_date' => '2026-08-26',
            'reason' => 'Damaged in transit',
        ]);

        PurchaseReturnLine::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'qty_base' => $qty,
            'unit_price' => '2500',
        ]);

        return $return->refresh();
    }

    private function receive(string $qty, string $cost): void
    {
        $receipt = app(DraftGoodsReceipt::class)($this->supplier, $this->warehouse->id, [
            ['product' => $this->product, 'qty' => $qty, 'unit_cost' => $cost],
        ], null, new CarbonImmutable('2026-08-26'));

        app(PostGoodsReceipt::class)($receipt);
    }
}
