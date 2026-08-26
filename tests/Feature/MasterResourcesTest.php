<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\PriceList;
use App\Domain\Masters\Models\PriceListItem;
use App\Domain\Masters\Models\Product;
use App\Domain\Masters\Models\ProductUom;
use App\Filament\Resources\BusinessPartners\BusinessPartnerResource;
use App\Filament\Resources\PriceLists\Pages\EditPriceList;
use App\Filament\Resources\PriceLists\RelationManagers\PriceListItemsRelationManager;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\ProductUomsRelationManager;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\User;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class MasterResourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs($this->userWithRole('Administrator'));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', $role)->value('id'));

        return $user;
    }

    /**
     * Every resource, not a hand-kept list of three. A generated resource ships
     * with `components([])` and renders a blank form under a real heading, so
     * it looks finished and passes any test that only asks for a 200 — this
     * asks each create screen for a field as well.
     */
    public function test_every_create_screen_renders_its_fields(): void
    {
        $resources = array_filter(
            Filament::getPanel('admin')->getResources(),
            fn (string $resource): bool => array_key_exists('create', $resource::getPages()),
        );

        $this->assertNotEmpty($resources);

        foreach ($resources as $resource) {
            $this->get($resource::getUrl('create'))->assertSuccessful();

            $fields = $resource::form(Schema::make(new ListProducts))->getFlatFields();

            $this->assertNotEmpty(
                $fields,
                sprintf('%s has a create page but an empty form schema.', class_basename($resource)),
            );
        }
    }

    public function test_every_master_list_screen_loads(): void
    {
        foreach ([ProductResource::class, BusinessPartnerResource::class, WarehouseResource::class] as $resource) {
            $this->get($resource::getUrl('index'))->assertSuccessful();
        }
    }

    public function test_a_product_is_created_through_the_form(): void
    {
        Livewire::test(ProductResource::getPages()['create']->getPage())
            ->fillForm([
                'sku' => 'KOPI-01',
                'name' => 'Kopi Susu 200ml',
                'base_uom' => 'PCS',
                'issue_method' => 'fefo',
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', ['sku' => 'KOPI-01', 'base_uom' => 'PCS']);
    }

    public function test_the_table_finds_a_product_by_sku(): void
    {
        $wanted = Product::factory()->create(['sku' => 'KOPI-01', 'name' => 'Kopi Susu']);
        $other = Product::factory()->create(['sku' => 'TEH-01', 'name' => 'Teh Botol']);

        Livewire::test(ListProducts::class)
            ->searchTable('KOPI')
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_a_broken_domain_rule_reaches_the_user_instead_of_a_stack_trace(): void
    {
        // "A partner needs at least one role" lives on the model and has no
        // form-level equivalent, so it is the rule that proves the form
        // reports a domain failure rather than falling over on it.
        Livewire::test(BusinessPartnerResource::getPages()['create']->getPage())
            ->fillForm([
                'code' => 'BP-NOROLE',
                'name' => 'Roleless Trading',
                'is_customer' => false,
                'is_supplier' => false,
                'is_principal' => false,
            ])
            ->call('create')
            ->assertNotified();

        $this->assertDatabaseMissing('business_partners', ['code' => 'BP-NOROLE']);
    }

    public function test_the_base_unit_field_locks_once_the_product_has_moved(): void
    {
        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
        $product = Product::factory()->create(['base_uom' => 'PCS']);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldEnabled('base_uom');

        app(StockPosting::class)->postDocument([new StockMovement(
            Warehouse::factory()->create()->id, $product->id, '10', '100', 'goods_receipt',
            (string) Str::uuid7(), new DateTimeImmutable('2026-08-26'),
        )]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldDisabled('base_uom');
    }

    public function test_adding_a_unit_goes_through_the_action_not_around_it(): void
    {
        $product = Product::factory()->create(['base_uom' => 'PCS']);
        ProductUom::create([
            'product_id' => $product->id, 'uom' => 'CARTON', 'qty_in_base' => '144', 'default_for_sale' => true,
        ]);

        Livewire::test(ProductUomsRelationManager::class, [
            'ownerRecord' => $product, 'pageClass' => EditProduct::class,
        ])
            ->callTableAction('create', data: [
                'uom' => 'PACK', 'qty_in_base' => '12', 'default_for_sale' => true,
            ])
            ->assertHasNoTableActionErrors();

        // The action's "one default each way" rule applied, which a form
        // writing the row itself would have missed.
        $this->assertSame(
            ['PACK'],
            ProductUom::where('product_id', $product->id)->where('default_for_sale', true)->pluck('uom')->all(),
        );
    }

    public function test_a_warehouse_missing_its_partner_is_reported_not_fatal(): void
    {
        Livewire::test(WarehouseResource::getPages()['create']->getPage())
            ->fillForm([
                'code' => 'CONS-1',
                'name' => 'Principal consignment',
                'warehouse_type' => 'consignment_in',
            ])
            ->call('create')
            ->assertHasFormErrors(['partner_id']);

        $this->assertDatabaseMissing('warehouses', ['code' => 'CONS-1']);
    }

    public function test_cost_is_hidden_from_a_sales_admin_and_shown_to_a_supervisor(): void
    {
        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
        $product = Product::factory()->create(['sku' => 'KOPI-01']);
        app(StockPosting::class)->postDocument([new StockMovement(
            Warehouse::factory()->create()->id, $product->id, '10', '2500', 'goods_receipt',
            (string) Str::uuid7(), new DateTimeImmutable('2026-08-26'),
        )]);

        // The sales admin takes orders all day and never needs to know what a
        // carton cost us.
        $this->actingAs($this->userWithRole('Sales Admin'));
        Livewire::test(ListProducts::class)
            ->assertTableColumnHidden('cost.average_cost')
            ->assertTableColumnHidden('cost.total_value');

        $this->actingAs($this->userWithRole('Sales Supervisor'));
        Livewire::test(ListProducts::class)
            ->assertTableColumnVisible('cost.average_cost')
            ->assertTableColumnStateSet('cost.average_cost', '2500.0000', $product);
    }

    public function test_margin_hides_with_cost_because_it_is_cost_by_another_name(): void
    {
        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
        $product = Product::factory()->create();
        app(StockPosting::class)->postDocument([new StockMovement(
            Warehouse::factory()->create()->id, $product->id, '10', '2500', 'goods_receipt',
            (string) Str::uuid7(), new DateTimeImmutable('2026-08-26'),
        )]);

        $priceList = PriceList::create(['name' => 'Wholesale', 'active' => true]);
        PriceListItem::create([
            'price_list_id' => $priceList->id, 'product_id' => $product->id,
            'uom' => 'PCS', 'min_qty' => '0', 'unit_price' => '4000.00',
        ]);

        $mount = ['ownerRecord' => $priceList, 'pageClass' => EditPriceList::class];

        $this->actingAs($this->userWithRole('Sales Supervisor'));
        Livewire::test(PriceListItemsRelationManager::class, $mount)
            ->assertTableColumnVisible('margin')
            // 4,000 sold against 2,500 cost is 37.5% of the price.
            ->assertSee('37.5%');

        $this->actingAs($this->userWithRole('Sales Admin'));
        Livewire::test(PriceListItemsRelationManager::class, $mount)
            ->assertTableColumnHidden('margin')
            ->assertDontSee('37.5%');
    }

    public function test_the_partner_form_shows_customer_terms_only_for_customers(): void
    {
        $supplier = BusinessPartner::factory()->create(['is_customer' => false, 'is_supplier' => true]);

        Livewire::test(BusinessPartnerResource::getPages()['edit']->getPage(), ['record' => $supplier->getRouteKey()])
            ->assertFormFieldHidden('credit_limit')
            ->fillForm(['is_customer' => true])
            ->assertFormFieldVisible('credit_limit');
    }
}
