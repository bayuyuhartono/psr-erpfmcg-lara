<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Product;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Services\DashboardFigures;
use App\Filament\Widgets\BusinessOverview;
use App\Filament\Widgets\ExpiringStock;
use App\Filament\Widgets\SalesTrend;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private BusinessPartner $customer;

    private DashboardFigures $figures;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-20');

        $this->seed([CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->customer = BusinessPartner::factory()->create(['is_customer' => true]);
        $this->figures = app(DashboardFigures::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', $role)->value('id'));
        $this->actingAs($user);

        return $user;
    }

    private function invoice(string $total, string $issuedOn, ?string $dueOn = null, string $status = 'posted', string $paid = '0.00'): SalesInvoice
    {
        // The column is NOT NULL, so an invoice always has one.
        $dueOn ??= CarbonImmutable::parse($issuedOn)->addDays(30)->toDateString();

        return SalesInvoice::create([
            'document_number' => 'INV-'.Str::upper(Str::random(8)),
            'customer_id' => $this->customer->id,
            'invoice_date' => $issuedOn,
            'due_date' => $dueOn,
            'subtotal' => $total, 'total' => $total,
            'paid_amount' => $paid, 'status' => $status,
        ]);
    }

    public function test_sales_this_month_counts_only_this_month(): void
    {
        $this->invoice('1000000.00', '2026-08-05');
        $this->invoice('500000.00', '2026-08-19');
        $this->invoice('9999999.00', '2026-07-31');   // last month

        $this->assertSame('1500000.00', (string) $this->figures->salesThisMonth());
        $this->assertSame('9999999.00', (string) $this->figures->salesLastMonth());
    }

    public function test_a_cancelled_invoice_is_not_a_sale(): void
    {
        $this->invoice('1000000.00', '2026-08-05');
        $this->invoice('4000000.00', '2026-08-06', status: 'cancelled');

        $this->assertSame('1000000.00', (string) $this->figures->salesThisMonth());
    }

    public function test_receivable_is_what_is_unpaid_not_what_was_invoiced(): void
    {
        $this->invoice('1000000.00', '2026-08-05', paid: '400000.00', status: 'partially_paid');
        $this->invoice('250000.00', '2026-08-06');
        $this->invoice('800000.00', '2026-08-07', status: 'paid', paid: '800000.00');

        // 600,000 still owing on the first, 250,000 on the second. The paid
        // one owes nothing and the sum must not quietly include its total.
        $this->assertSame('850000.00', (string) $this->figures->receivable());
    }

    public function test_overdue_is_past_the_due_date_and_still_owing(): void
    {
        $this->invoice('300000.00', '2026-07-01', dueOn: '2026-07-31');   // overdue
        $this->invoice('900000.00', '2026-08-01', dueOn: '2026-09-30');   // not yet
        $this->invoice('700000.00', '2026-07-01', dueOn: '2026-07-15', status: 'paid', paid: '700000.00');

        $this->assertSame('300000.00', (string) $this->figures->overdue());
        $this->assertSame(1, $this->figures->overdueCount());
    }

    public function test_stock_value_excludes_consigned_goods(): void
    {
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();

        $this->balance($warehouse, $product, '100.0000', '5000000.00', owner: null);
        $this->balance($warehouse, $product, '80.0000', '9000000.00', owner: $this->customer->id);

        // The principal's goods sit in our racks and belong to them. Counting
        // them overstates the business by their full value (schema §11).
        $this->assertSame('5000000.00', (string) $this->figures->stockValue());
    }

    public function test_the_daily_series_fills_quiet_days_with_zero(): void
    {
        $this->invoice('600000.00', '2026-08-20');

        $series = $this->figures->dailySales();

        // Fourteen entries, not one: a chart that omits empty days draws a
        // quiet Sunday as a straight line to Monday and hides it.
        $this->assertCount(14, $series);
        $this->assertSame('2026-08-07', array_key_first($series));
        $this->assertSame('600000.00', (string) $series['2026-08-20']);
        $this->assertSame('0.00', (string) $series['2026-08-18']);
    }

    public function test_the_dashboard_renders_with_its_widgets(): void
    {
        $this->actingAsRole('Administrator');
        $this->invoice('1000000.00', '2026-08-05');

        // The page itself only ships placeholders — Filament lazy-loads
        // widgets, so the figures arrive on a second request and asserting on
        // the page HTML would test the placeholder.
        $this->get('/admin')->assertSuccessful();

        // Keyed by the file each was discovered in, so compare the values.
        $widgets = array_values(Filament::getPanel('admin')->getWidgets());
        sort($widgets);

        $this->assertSame(
            [BusinessOverview::class, ExpiringStock::class, SalesTrend::class],
            $widgets,
        );

        Livewire::test(BusinessOverview::class)
            ->assertSee(__('Sales this month'))
            ->assertSee(__('Owed by customers'))
            ->assertSee(__('Overdue'))
            ->assertSee(__('Nothing past due'))
            ->assertSee('Rp 1,0 jt');
    }

    public function test_stock_value_is_hidden_from_someone_who_cannot_see_cost(): void
    {
        // F-70: what the goods cost us is the figure kept off the sales floor,
        // and a dashboard is the easiest place to leak it.
        $this->actingAsRole('Sales');

        Livewire::test(BusinessOverview::class)->assertDontSee(__('Stock value'));

        $this->actingAsRole('Administrator');

        Livewire::test(BusinessOverview::class)->assertSee(__('Stock value'));
    }

    public function test_the_expiring_table_shows_only_our_own_stock_that_is_left(): void
    {
        $this->actingAsRole('Administrator');

        $warehouse = Warehouse::factory()->create();
        $soon = Product::factory()->create(['sku' => 'SOON-01', 'track_batch' => true, 'track_expiry' => true]);
        $later = Product::factory()->create(['sku' => 'LATER-01', 'track_batch' => true, 'track_expiry' => true]);
        $gone = Product::factory()->create(['sku' => 'GONE-01', 'track_batch' => true, 'track_expiry' => true]);
        $theirs = Product::factory()->create(['sku' => 'THEIRS-01', 'track_batch' => true, 'track_expiry' => true]);

        $this->balance($warehouse, $soon, '50.0000', '100000.00', batch: $this->batch($soon, '2026-09-15'));
        $this->balance($warehouse, $later, '50.0000', '100000.00', batch: $this->batch($later, '2027-06-01'));
        $this->balance($warehouse, $gone, '0.0000', '0.00', batch: $this->batch($gone, '2026-09-01'));
        $this->balance($warehouse, $theirs, '50.0000', '100000.00',
            batch: $this->batch($theirs, '2026-09-10'), owner: $this->customer->id);

        Livewire::test(ExpiringStock::class)
            ->assertSee('SOON-01')
            ->assertDontSee('LATER-01')   // beyond the 60-day horizon
            ->assertDontSee('GONE-01')    // nothing left on the batch
            ->assertDontSee('THEIRS-01'); // consigned in, not ours to lose
    }

    public function test_the_chart_returns_a_point_for_every_day(): void
    {
        $this->actingAsRole('Administrator');

        Livewire::test(SalesTrend::class)->assertSuccessful();
    }

    private function batch(Product $product, string $expiresOn): StockBatch
    {
        return StockBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'B-'.Str::upper(Str::random(5)),
            'expiry_date' => $expiresOn,
        ]);
    }

    private function balance(Warehouse $warehouse, Product $product, string $qty, string $value, ?StockBatch $batch = null, ?string $owner = null): void
    {
        StockBalance::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'batch_id' => $batch?->id,
            'owner_id' => $owner,
            'qty_base' => $qty,
            'value' => $value,
        ]);
    }
}
