<?php

namespace Tests\Feature;

use App\Domain\Accounting\Actions\CloseAccountingPeriod;
use App\Domain\Accounting\Exceptions\PeriodNotReady;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalMapping;
use App\Domain\Accounting\Services\FinancialStatements;
use App\Domain\Accounting\Services\GeneralLedger;
use App\Domain\Accounting\Services\InventoryValuation;
use App\Domain\Inventory\Models\Warehouse;
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
use App\Domain\Sales\Actions\RecordProofOfDelivery;
use App\Domain\Sales\Actions\ShipDelivery;
use App\Domain\Sales\Actions\SubmitSalesOrder;
use App\Filament\Resources\ChartOfAccounts\ChartOfAccountResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\JournalMappings\JournalMappingResource;
use App\Models\User;
use App\Support\Posting\Exceptions\ClosedPeriod;
use App\Support\Posting\Exceptions\UnbalancedJournal;
use App\Support\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

final class AccountingTest extends TestCase
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
        $this->warehouse = Warehouse::factory()->create();
        $this->supplier = BusinessPartner::factory()->create(['is_supplier' => true]);
        $this->product = Product::factory()->create(['sku' => 'KOPI-01', 'base_uom' => 'PCS', 'vat_percent' => '11.00']);

        $list = PriceList::create(['name' => 'Wholesale', 'active' => true]);
        PriceListItem::create([
            'price_list_id' => $list->id, 'product_id' => $this->product->id,
            'uom' => 'PCS', 'min_qty' => '0', 'unit_price' => '5000',
        ]);
        $tier = CustomerTier::create(['name' => 'Wholesale', 'price_list_id' => $list->id]);
        $this->customer = BusinessPartner::factory()->create(['tier_id' => $tier->id, 'credit_limit' => '50000000']);
    }

    // 7.2 — every document journals itself

    public function test_a_full_cycle_leaves_a_journal_behind_every_document(): void
    {
        $this->buyAndSell();

        $types = JournalEntry::query()->pluck('ref_type')->unique()->sort()->values()->all();

        // Nothing was posted by hand anywhere in that cycle.
        $this->assertSame(['delivery', 'goods_receipt', 'sales_invoice'], $types);
        $this->assertSame(0, JournalEntry::where('status', 'draft')->count());
    }

    public function test_every_entry_balances(): void
    {
        $this->buyAndSell();

        foreach (JournalEntry::with('journalLines')->get() as $entry) {
            $debits = $entry->journalLines->sum(fn ($l) => (float) (string) $l->debit);
            $credits = $entry->journalLines->sum(fn ($l) => (float) (string) $l->credit);
            $this->assertEqualsWithDelta($debits, $credits, 0.001, "Entry {$entry->document_number} does not balance");
        }
    }

    // 7.3 — manual journals

    public function test_a_manual_journal_names_its_own_accounts(): void
    {
        $entry = app(JournalPoster::class)->postManual(
            'Bank charges for August',
            $this->today,
            [
                ['account_id' => $this->account('5102')->id, 'debit' => '75000', 'description' => 'Monthly fee'],
                ['account_id' => $this->account('1101')->id, 'credit' => '75000'],
            ],
            actorId: $this->financeManager()->id,
        );

        $this->assertSame('posted', $entry->status);
        $this->assertSame('manual', $entry->ref_type);
        $this->assertCount(2, $entry->journalLines);
    }

    public function test_a_manual_journal_that_does_not_balance_is_refused(): void
    {
        $this->expectException(UnbalancedJournal::class);

        app(JournalPoster::class)->postManual('Wrong', $this->today, [
            ['account_id' => $this->account('5102')->id, 'debit' => '75000'],
            ['account_id' => $this->account('1101')->id, 'credit' => '70000'],
        ]);
    }

    public function test_a_manual_journal_obeys_the_period_lock(): void
    {
        $this->closePeriod(2026, 7);

        $this->expectException(ClosedPeriod::class);

        app(JournalPoster::class)->postManual('Backdated', new CarbonImmutable('2026-07-15'), [
            ['account_id' => $this->account('5102')->id, 'debit' => '1000'],
            ['account_id' => $this->account('1101')->id, 'credit' => '1000'],
        ]);
    }

    // 7.4 — general ledger

    public function test_the_ledger_runs_a_balance_and_points_at_the_documents(): void
    {
        $this->buyAndSell();

        $ledger = app(GeneralLedger::class)->for(
            $this->account('1103'), new CarbonImmutable('2026-08-01'), new CarbonImmutable('2026-08-31'),
        );

        // 500 in at 3,000; 100 out at cost.
        $this->assertSame('0.00', $ledger['opening']);
        $this->assertSame('1200000.00', $ledger['closing']);
        $this->assertSame('goods_receipt', $ledger['movements'][0]['ref_type']);
        $this->assertNotSame('', $ledger['movements'][0]['ref_id']);
    }

    public function test_a_payable_reads_as_a_positive_balance(): void
    {
        $this->buyAndSell();

        // Credit-side accounts are reported in the direction they carry, not
        // as negative debits. The receipt parked the goods value here; VAT
        // arrives later, with the supplier invoice.
        $this->assertSame('1500000.00', (string) app(GeneralLedger::class)->balanceAt($this->account('2103'), $this->today));
    }

    // 7.5 — statements

    public function test_the_profit_and_loss_shows_the_margin_on_what_was_sold(): void
    {
        $this->buyAndSell();

        $pnl = app(FinancialStatements::class)->profitAndLoss(new CarbonImmutable('2026-08-01'), new CarbonImmutable('2026-08-31'));

        // 100 sold at 5,000 costing 3,000.
        $this->assertSame('500000.00', $pnl['total_revenue']);
        $this->assertSame('300000.00', $pnl['total_expenses']);
        $this->assertSame('200000.00', $pnl['profit']);
    }

    public function test_the_balance_sheet_balances(): void
    {
        $this->buyAndSell();

        $sheet = app(FinancialStatements::class)->balanceSheet($this->today);

        $this->assertTrue($sheet['balances'], sprintf(
            'Assets %s against liabilities and equity %s',
            $sheet['total_assets'],
            $sheet['total_liabilities_and_equity'],
        ));
        $this->assertSame('200000.00', $sheet['retained_this_year']);
    }

    // 7.6 — valuation excludes consigned

    public function test_valuation_reconciles_and_leaves_the_principals_goods_out(): void
    {
        $this->buyAndSell();

        $principal = BusinessPartner::factory()->create(['is_principal' => true, 'is_supplier' => true]);
        $consignment = Warehouse::factory()->create(['warehouse_type' => 'consignment_in', 'partner_id' => $principal->id]);
        $receipt = app(DraftGoodsReceipt::class)($principal, $consignment->id, [
            ['product' => $this->product, 'qty' => '200', 'unit_cost' => '3000'],
        ], null, $this->today, owner: $principal);
        app(PostGoodsReceipt::class)($receipt);

        $reconciliation = app(InventoryValuation::class)->reconcile($this->today);

        // 600,000 of the principal's goods are in the warehouse and in neither
        // the stock value nor the inventory account.
        $this->assertSame('600000.00', $reconciliation['consigned_excluded']);
        $this->assertSame('1200000.00', $reconciliation['stock_value']);
        $this->assertTrue($reconciliation['reconciles']);
    }

    public function test_a_stock_figure_that_disagrees_with_the_ledger_is_reported(): void
    {
        $this->buyAndSell();
        DB::table('stock_balances')->update(['value' => '999999.00']);

        $this->assertFalse(app(InventoryValuation::class)->reconcile($this->today)['reconciles']);
    }

    // 7.7 — closing

    #[Group('slow')]
    public function test_closing_a_month_takes_seconds_not_minutes(): void
    {
        $this->buyAndSell();

        $started = microtime(true);
        app(CloseAccountingPeriod::class)(2026, 8, $this->financeManager());
        $seconds = microtime(true) - $started;

        $this->assertSame('closed', AccountingPeriod::where(['year' => 2026, 'month' => 8])->value('status'));
        $this->assertLessThan(120, $seconds);
        fwrite(STDERR, sprintf("\n  Month-end close: %.2fs\n", $seconds));
    }

    public function test_a_month_with_unposted_journals_will_not_close(): void
    {
        $this->buyAndSell();
        app(JournalPoster::class)->postManual('Half finished', $this->today, [
            ['account_id' => $this->account('5102')->id, 'debit' => '1000'],
            ['account_id' => $this->account('1101')->id, 'credit' => '1000'],
        ], status: 'draft');

        $this->expectException(PeriodNotReady::class);
        $this->expectExceptionMessageMatches('/1 unposted journal/');

        app(CloseAccountingPeriod::class)(2026, 8, $this->financeManager());
    }

    public function test_a_month_whose_stock_does_not_reconcile_will_not_close_quietly(): void
    {
        $this->buyAndSell();
        DB::table('stock_balances')->update(['value' => '1.00']);

        try {
            app(CloseAccountingPeriod::class)(2026, 8, $this->financeManager());
            $this->fail('Expected the close to refuse.');
        } catch (PeriodNotReady $exception) {
            $this->assertStringContainsString('stock:rebuild-balances', $exception->getMessage());
        }

        // Closeable once someone has understood and accepted the difference.
        app(CloseAccountingPeriod::class)(2026, 8, $this->financeManager(), force: true);
        $this->assertSame('closed', AccountingPeriod::where(['year' => 2026, 'month' => 8])->value('status'));
    }

    public function test_a_closed_month_refuses_what_comes_after(): void
    {
        $this->buyAndSell();
        app(CloseAccountingPeriod::class)(2026, 8, $this->financeManager());

        $this->expectException(ClosedPeriod::class);

        app(JournalPoster::class)->postManual('Too late', $this->today, [
            ['account_id' => $this->account('5102')->id, 'debit' => '1000'],
            ['account_id' => $this->account('1101')->id, 'credit' => '1000'],
        ]);
    }

    public function test_the_accounting_screens_load_and_journals_are_read_only(): void
    {
        $this->buyAndSell();
        $this->actingAs($this->financeManager());

        foreach ([
            ChartOfAccountResource::class,
            JournalMappingResource::class,
            JournalEntryResource::class,
        ] as $resource) {
            $this->get($resource::getUrl('index'))->assertSuccessful();
        }

        // The kernel writes entries; the screen reads them. A correction is a
        // reversal, not an edit.
        $pages = JournalEntryResource::getPages();
        $this->assertSame(['index'], array_keys($pages));
    }

    public function test_remapping_an_account_changes_where_the_next_document_posts(): void
    {
        // The screen exists so a client with a different chart never needs a
        // code change. Prove the mapping is what decides.
        $variance = $this->account('5102');
        JournalMapping::query()
            ->where(['document_type' => 'goods_receipt', 'posting_key' => 'grir'])
            ->update(['account_id' => $variance->id]);

        $receipt = app(DraftGoodsReceipt::class)($this->supplier, $this->warehouse->id, [
            ['product' => $this->product, 'qty' => '10', 'unit_cost' => '3000'],
        ], null, $this->today);
        app(PostGoodsReceipt::class)($receipt);

        $codes = JournalEntry::where('ref_type', 'goods_receipt')->firstOrFail()
            ->journalLines()->with('account')->get()->pluck('account.code')->sort()->values()->all();

        $this->assertSame(['1103', '5102'], $codes);
    }

    /** Buy 500 at 3,000, sell 100 at 5,000, all the way to a posted invoice. */
    private function buyAndSell(): void
    {
        $receipt = app(DraftGoodsReceipt::class)($this->supplier, $this->warehouse->id, [
            ['product' => $this->product, 'qty' => '500', 'unit_cost' => '3000'],
        ], null, $this->today);
        app(PostGoodsReceipt::class)($receipt);

        $order = app(SubmitSalesOrder::class)(app(DraftSalesOrder::class)(
            $this->customer, $this->warehouse->id, [['product' => $this->product, 'qty' => '100']], $this->today,
        ));

        $delivery = app(PlanDelivery::class)([$order], $this->today);
        app(ShipDelivery::class)($delivery);
        app(RecordProofOfDelivery::class)($delivery->fresh(), [], 'Ibu Sari', $this->today);
        app(InvoiceDelivery::class)($delivery->fresh());
    }

    private function closePeriod(int $year, int $month): void
    {
        AccountingPeriod::create(['year' => $year, 'month' => $month])
            ->transitionTo('closed', $this->financeManager());
    }

    private function account(string $code): ChartOfAccount
    {
        return ChartOfAccount::where('code', $code)->firstOrFail();
    }

    private function financeManager(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', 'Finance Manager')->value('id'));

        return $user;
    }
}
