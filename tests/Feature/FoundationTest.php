<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\Branch;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\NumberSequence;
use App\Domain\Masters\Models\Product;
use App\Models\User;
use App\Support\Posting\DocumentNumberer;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_uuid7_and_audit_log_are_written_for_model_changes(): void
    {
        $branch = Branch::factory()->create();
        $branch->update(['name' => 'Renamed Branch']);

        $this->assertSame('7', $branch->id[14]);
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'branches', 'record_id' => $branch->id, 'action' => 'insert']);
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'branches', 'record_id' => $branch->id, 'action' => 'update']);
    }

    public function test_document_numberer_increments_a_locked_sequence(): void
    {
        $branch = Branch::factory()->create(['code' => 'JKT']);
        NumberSequence::create([
            'branch_id' => $branch->id, 'document_type' => 'SO', 'prefix' => 'SO/{BRANCH}/{YY}{MM}/',
            'period' => '2026-08', 'last_number' => 0,
        ]);

        $numberer = app(DocumentNumberer::class);
        $date = new CarbonImmutable('2026-08-25');

        $this->assertSame('SO/JKT/2608/000001', $numberer->next('SO', $date, $branch));
        $this->assertSame('SO/JKT/2608/000002', $numberer->next('SO', $date, $branch));
    }

    public function test_owned_stock_scope_excludes_consigned_balances(): void
    {
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $owner = BusinessPartner::factory()->create();
        $base = ['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_base' => '1', 'value' => '10'];
        StockBalance::create($base);
        StockBalance::create($base + ['owner_id' => $owner->id]);

        $this->assertSame(1, StockBalance::count());
        $this->assertSame(2, StockBalance::includingConsigned()->count());
    }

    public function test_foundation_seeders_are_idempotent(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('company_settings', 1);
        $this->assertDatabaseCount('roles', 5);
        $this->assertDatabaseCount('chart_of_accounts', 9);
        $this->assertDatabaseCount('journal_mappings', 11);
    }

    public function test_role_permission_thresholds_are_enforced(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('name', 'Sales Supervisor')->value('id');
        $user->roles()->attach($roleId);

        $this->assertTrue($user->hasPermission('discount.approve', '20.00'));
        $this->assertFalse($user->hasPermission('discount.approve', '20.01'));
        $this->assertTrue($user->hasPermission('sales_order.approve'));
        $this->assertFalse($user->hasPermission('journal.post'));
    }
}
