<?php

namespace Tests\Feature;

use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Sales\Models\SalesOrder;
use App\Filament\Pages\SalesOrderEntry;
use App\Models\User;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_installation_says_what_it_is_missing(): void
    {
        // The symptom this fixes: a screen that quietly refuses to do anything
        // is indistinguishable from a broken one.
        $this->signIn();

        Livewire::test(SalesOrderEntry::class)
            ->assertSee('Before you can take an order')
            ->assertSee('No active warehouse')
            ->assertSee('No customers yet')
            ->assertSee('No products yet');
    }

    public function test_pressing_enter_with_no_customer_says_so(): void
    {
        $this->signIn();

        Livewire::test(SalesOrderEntry::class)
            ->call('addLine')
            ->assertNotified('Choose a customer first');
    }

    public function test_a_search_that_matches_nothing_says_so(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->signIn();

        Livewire::test(SalesOrderEntry::class)
            ->set('customerId', BusinessPartner::customers()->value('id'))
            ->set('search', 'NOTHING-LIKE-THIS')
            ->call('addLine')
            ->assertNotified();
    }

    public function test_the_demo_data_makes_order_entry_work_end_to_end(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->signIn();

        $customer = BusinessPartner::customers()->where('code', 'C-00001')->firstOrFail();

        Livewire::test(SalesOrderEntry::class)
            ->assertDontSee('Before you can take an order')
            ->set('customerId', $customer->id)
            ->set('search', 'KOPI-250')
            ->set('quantity', '10')
            ->call('addLine')
            ->assertCount('lines', 1)
            ->call('save')
            ->assertNotified();

        $order = SalesOrder::firstOrFail();

        $this->assertSame('approved', $order->status);
        $this->assertSame($customer->id, $order->customer_id);
        // Priced from the tier's list, with the tier and brand discounts on it.
        $this->assertSame('2.50', (string) $order->salesOrderLines()->value('discount1_percent'));
        $this->assertSame('3.00', (string) $order->salesOrderLines()->value('discount2_percent'));
    }

    public function test_it_brings_its_own_configuration_on_a_bare_database(): void
    {
        // Someone reaching for the demo data on a fresh install should not
        // have to know it depends on the chart of accounts being seeded first.
        $this->seed(DemoDataSeeder::class);

        $this->assertGreaterThan(0, DB::table('chart_of_accounts')->count());
        $this->assertGreaterThan(0, DB::table('stock_ledger')->count());
        $this->assertGreaterThan(0, DB::table('journal_entries')->count());
    }

    public function test_the_demo_data_can_be_seeded_twice(): void
    {
        $this->seed(DemoDataSeeder::class);
        $products = DB::table('products')->count();

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($products, DB::table('products')->count());
    }

    private function signIn(): void
    {
        $this->seed([CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class, NumberSequencesSeeder::class]);
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', 'Administrator')->value('id'));
        $this->actingAs($user);
    }
}
