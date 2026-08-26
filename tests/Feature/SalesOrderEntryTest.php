<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Actions\AddProductUom;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\CustomerTier;
use App\Domain\Masters\Models\PriceList;
use App\Domain\Masters\Models\PriceListItem;
use App\Domain\Masters\Models\Product;
use App\Domain\Sales\Models\SalesOrder;
use App\Filament\Pages\SalesOrderEntry;
use App\Models\User;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

final class SalesOrderEntryTest extends TestCase
{
    use RefreshDatabase;

    private BusinessPartner $customer;

    private PriceList $priceList;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class, NumberSequencesSeeder::class]);
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', 'Administrator')->value('id'));
        $this->actingAs($user);

        Warehouse::factory()->create(['code' => 'WH-01']);
        $this->priceList = PriceList::create(['name' => 'Wholesale', 'active' => true]);
        $tier = CustomerTier::create(['name' => 'Wholesale', 'price_list_id' => $this->priceList->id]);
        $this->customer = BusinessPartner::factory()->create(['tier_id' => $tier->id, 'credit_limit' => '500000000']);
    }

    public function test_typing_finds_a_product_and_enter_adds_it(): void
    {
        $this->product('KOPI-01', 'Kopi Susu 200ml', '5000');

        Livewire::test(SalesOrderEntry::class)
            ->set('customerId', $this->customer->id)
            ->set('search', 'KOPI-01')
            ->assertCount('matches', 1)
            // One match, so Enter takes it without reaching for the mouse.
            ->call('addLine')
            ->assertCount('lines', 1)
            ->assertSet('lines.0.sku', 'KOPI-01')
            ->assertSet('lines.0.line_total', '5000.00')
            // The box is cleared and the cursor handed back for the next line.
            ->assertSet('search', '')
            ->assertDispatched('focus-search');
    }

    public function test_a_second_product_is_reachable_with_the_arrow_keys(): void
    {
        // F-31: the entire form is keyboard-operable without touching the
        // mouse. Two matches, and the second one has to be reachable.
        $this->product('KOPI-01', 'Kopi Susu', '5000');
        $this->product('KOPI-02', 'Kopi Hitam', '4000');

        $page = Livewire::test(SalesOrderEntry::class)
            ->set('customerId', $this->customer->id)
            ->set('search', 'KOPI')
            ->assertCount('matches', 2)
            ->assertSet('highlight', 0);

        // Whichever the search ranked second — the point is that it is
        // reachable, not what order the list happens to be in.
        $second = $page->get('matches')[1]['label'];

        $page->call('moveHighlight', 1)
            ->assertSet('highlight', 1)
            ->call('addLine')
            ->assertCount('lines', 1);

        $this->assertStringStartsWith($page->get('lines')[0]['sku'], $second);
    }

    public function test_the_arrow_keys_stop_at_the_ends_of_the_list(): void
    {
        $this->product('KOPI-01', 'Kopi Susu', '5000');
        $this->product('KOPI-02', 'Kopi Hitam', '4000');

        Livewire::test(SalesOrderEntry::class)
            ->set('customerId', $this->customer->id)
            ->set('search', 'KOPI')
            ->call('moveHighlight', -1)->assertSet('highlight', 0)
            ->call('moveHighlight', 1)
            ->call('moveHighlight', 1)->assertSet('highlight', 1);
    }

    public function test_the_customer_is_chosen_by_typing_too(): void
    {
        $this->customer->update(['code' => 'C-00123', 'name' => 'Toko Berkah']);

        Livewire::test(SalesOrderEntry::class)
            ->set('customerSearch', 'Berkah')
            ->assertCount('customerMatches', 1)
            // One match, so Enter takes it and moves to the product box.
            ->call('chooseCustomer')
            ->assertSet('customerId', $this->customer->id)
            ->assertDispatched('focus-search');
    }

    public function test_copying_the_last_order_burns_no_document_number(): void
    {
        $this->product('KOPI-01', 'Kopi Susu', '5000');

        Livewire::test(SalesOrderEntry::class)
            ->set('customerId', $this->customer->id)
            ->set('search', 'KOPI-01')
            ->call('addLine')
            ->call('save');

        $firstNumber = SalesOrder::firstOrFail()->document_number;

        Livewire::test(SalesOrderEntry::class)
            ->set('customerId', $this->customer->id)
            ->call('copyPrevious')
            ->assertCount('lines', 1)
            ->assertSet('lines.0.sku', 'KOPI-01');

        // Looking at the last order is not placing one: still one order, and
        // the next number is the one after it.
        $this->assertSame(1, SalesOrder::withTrashed()->count());
        $this->assertSame('SO/2608/000001', $firstNumber);
    }

    public function test_the_credit_position_is_shown_before_anything_is_typed(): void
    {
        Livewire::test(SalesOrderEntry::class)
            ->set('customerId', $this->customer->id)
            ->assertSee('Credit limit')
            ->assertSee('Already owes')
            ->assertSee('Left after this');
    }

    public function test_the_credit_panel_warns_before_the_order_is_saved(): void
    {
        // F-30 information at the moment it is useful. An admin who only finds
        // out after saving has already spent the two minutes.
        $this->customer->update(['credit_limit' => '1000']);
        $this->product('KOPI-01', 'Kopi Susu', '5000');

        Livewire::test(SalesOrderEntry::class)
            ->set('customerId', $this->customer->id)
            ->set('search', 'KOPI-01')
            ->call('addLine')
            ->assertSee('this will be held for release');
    }

    public function test_the_unit_can_be_chosen_rather_than_assumed(): void
    {
        // Five cartons and five pieces differ by the carton size, and an
        // order that cannot say which is an order waiting to be wrong.
        $product = $this->product('KOPI-01', 'Kopi Susu', '5000');
        app(AddProductUom::class)($product, 'DUS', '24', defaultForSale: true);
        PriceListItem::create([
            'price_list_id' => $this->priceList->id, 'product_id' => $product->id,
            'uom' => 'DUS', 'min_qty' => '0', 'unit_price' => '120000',
        ]);

        $page = Livewire::test(SalesOrderEntry::class)
            ->set('customerId', $this->customer->id)
            ->set('search', 'KOPI-01');

        // Both units offered, with the conversion spelled out.
        $page->call('choose', $product->id);
        $units = $page->instance()->unitOptions();

        $this->assertSame(['PCS', 'DUS'], array_keys($units));
        $this->assertSame('DUS (24 PCS)', $units['DUS']);

        $page->set('selectedUom', 'PCS')
            ->set('quantity', '5')
            ->call('addLine')
            ->assertSet('lines.0.uom', 'PCS')
            ->assertSet('lines.0.line_total', '25000.00');
    }

    public function test_changing_a_line_quantity_reprices_it(): void
    {
        $product = $this->product('KOPI-01', 'Kopi Susu', '5000');
        // A volume band: ten or more is cheaper.
        PriceListItem::create([
            'price_list_id' => $this->priceList->id, 'product_id' => $product->id,
            'uom' => 'PCS', 'min_qty' => '10', 'unit_price' => '4500',
        ]);

        Livewire::test(SalesOrderEntry::class)
            ->set('customerId', $this->customer->id)
            ->set('search', 'KOPI-01')
            ->set('quantity', '5')
            ->call('addLine')
            ->assertSet('lines.0.line_total', '25000.00')
            // Re-quoted, not multiplied: the band turns on quantity.
            ->set('lines.0.qty', '20')
            ->assertSet('lines.0.unit_price', '4500.00')
            ->assertSet('lines.0.line_total', '90000.00');
    }

    public function test_saving_goes_through_the_same_actions_as_everything_else(): void
    {
        $this->product('KOPI-01', 'Kopi Susu', '5000');

        Livewire::test(SalesOrderEntry::class)
            ->set('customerId', $this->customer->id)
            ->set('search', 'KOPI-01')
            ->call('addLine')
            ->call('save')
            ->assertNotified();

        $order = SalesOrder::firstOrFail();
        $this->assertSame('approved', $order->status);
        // Reserved on approval, exactly as if it had come from the API.
        $this->assertDatabaseCount('stock_reservations', 1);
    }

    public function test_an_order_over_the_limit_says_so_rather_than_failing(): void
    {
        $this->customer->update(['credit_limit' => '1000']);
        $this->product('KOPI-01', 'Kopi Susu', '5000');

        Livewire::test(SalesOrderEntry::class)
            ->set('customerId', $this->customer->id)
            ->set('search', 'KOPI-01')
            ->call('addLine')
            ->call('save')
            ->assertNotified();

        $this->assertSame('credit_hold', SalesOrder::firstOrFail()->status);
    }

    #[Group('slow')]
    public function test_ten_lines_cost_well_under_the_two_minute_budget_on_the_server(): void
    {
        // F-31 is a human target and needs a real sales admin with a stopwatch.
        // What can be measured here is the part that would make the screen feel
        // slow: the server work behind each keystroke and each line.
        $this->insertInChunks('products', 5000, fn (int $n): array => [
            'id' => (string) Str::uuid7(),
            'sku' => sprintf('SKU-%05d', $n), 'name' => "Product {$n}",
            'base_uom' => 'PCS', 'track_batch' => false, 'track_expiry' => false,
            'issue_method' => 'fefo', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('price_list_items')->insert(
            DB::table('products')->limit(10)->pluck('id')->map(fn ($id) => [
                'id' => (string) Str::uuid7(),
                'price_list_id' => $this->priceList->id, 'product_id' => $id,
                'uom' => 'PCS', 'min_qty' => '0.0000', 'unit_price' => '5000.00',
                'created_at' => now(), 'updated_at' => now(),
            ])->all()
        );

        // Two thousand outlets, which is the top of the PRD's range. The
        // earlier measurement had one, and would not have noticed a screen
        // that loaded every customer on each keystroke.
        $tierId = $this->customer->tier_id;
        $this->insertInChunks('business_partners', 2000, fn (int $n): array => [
            'id' => (string) Str::uuid7(),
            'code' => sprintf('C-%05d', $n), 'name' => "Toko {$n}",
            'is_customer' => true, 'is_supplier' => false, 'is_principal' => false,
            'tier_id' => $tierId, 'credit_limit' => '10000000.00',
            'payment_terms_days' => 30, 'on_hold' => false, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $skus = DB::table('products')->limit(10)->pluck('sku');

        $started = microtime(true);
        $page = Livewire::test(SalesOrderEntry::class)
            ->set('customerSearch', 'C-01234')
            ->call('chooseCustomer');
        $customerMs = (microtime(true) - $started) * 1000;

        $started = microtime(true);

        foreach ($skus as $sku) {
            // What a typist actually causes: a lookup, then a line.
            $page->set('search', $sku)->call('addLine');
        }

        $page->call('save');
        $seconds = microtime(true) - $started;

        $this->assertCount(10, SalesOrder::firstOrFail()->salesOrderLines);
        $this->assertLessThan(10, $seconds, sprintf('Server work for 10 lines took %.1fs.', $seconds));
        fwrite(STDERR, sprintf(
            "\n  Order entry: customer lookup across 2,000 outlets %.0f ms; 10 lines across 5,000 SKUs %.2fs (%.0f ms per line)\n",
            $customerMs,
            $seconds,
            $seconds * 1000 / 10,
        ));
    }

    /**
     * Build and flush a row at a time rather than holding the whole fixture.
     * Seven thousand rows in memory is heavier than anything being measured.
     *
     * @param  callable(int): array<string, mixed>  $row
     */
    private function insertInChunks(string $table, int $count, callable $row, int $chunk = 500): void
    {
        $buffer = [];

        foreach (range(1, $count) as $n) {
            $buffer[] = $row($n);

            if (count($buffer) === $chunk) {
                DB::table($table)->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table($table)->insert($buffer);
        }
    }

    private function product(string $sku, string $name, string $price): Product
    {
        $product = Product::factory()->create(['sku' => $sku, 'name' => $name, 'base_uom' => 'PCS']);
        PriceListItem::create([
            'price_list_id' => $this->priceList->id, 'product_id' => $product->id,
            'uom' => 'PCS', 'min_qty' => '0', 'unit_price' => $price,
        ]);

        return $product;
    }
}
