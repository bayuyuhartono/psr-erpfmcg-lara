<?php

namespace Tests\Feature;

use App\Domain\Inventory\Exceptions\InvalidWarehouseType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseLocation;
use App\Domain\Masters\Exceptions\InvalidPartnerRoles;
use App\Domain\Masters\Exceptions\PartnerRoleInUse;
use App\Domain\Masters\Models\Brand;
use App\Domain\Masters\Models\BusinessPartner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PartnerAndWarehouseTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_company_can_hold_several_roles_at_once(): void
    {
        $partner = BusinessPartner::factory()->create([
            'is_principal' => true, 'is_supplier' => true, 'is_customer' => true,
        ]);

        $this->assertTrue(BusinessPartner::principals()->whereKey($partner->id)->exists());
        $this->assertTrue(BusinessPartner::suppliers()->whereKey($partner->id)->exists());
        $this->assertTrue(BusinessPartner::customers()->whereKey($partner->id)->exists());
    }

    public function test_a_partner_with_no_role_is_refused(): void
    {
        $this->expectException(InvalidPartnerRoles::class);
        $this->expectExceptionMessageMatches('/has no role/');

        BusinessPartner::factory()->create(['is_customer' => false]);
    }

    public function test_a_role_cannot_be_dropped_while_documents_still_use_it(): void
    {
        $principal = BusinessPartner::factory()->create(['is_principal' => true]);
        Brand::create(['principal_id' => $principal->id, 'code' => 'BR-1', 'name' => 'Kopi Susu']);

        try {
            $principal->update(['is_principal' => false]);
            $this->fail('Expected the principal role to be held by its brands.');
        } catch (PartnerRoleInUse $exception) {
            $this->assertStringContainsString('brands', $exception->getMessage());
            $this->assertTrue($principal->fresh()->is_principal);
        }

        // Deactivating is the supported way to retire a partner: the documents
        // keep their role, the partner stops appearing in pickers. Refresh
        // first — a rejected save leaves the refused value on the instance.
        $principal->refresh();
        $principal->update(['active' => false]);
        $this->assertFalse($principal->fresh()->active);
    }

    public function test_an_unused_role_can_still_be_dropped(): void
    {
        $partner = BusinessPartner::factory()->create(['is_customer' => true, 'is_supplier' => true]);

        $partner->update(['is_supplier' => false]);

        $this->assertFalse($partner->fresh()->is_supplier);
    }

    public function test_exchange_and_payment_days_round_trip_as_integers(): void
    {
        $partner = BusinessPartner::factory()->create([
            'invoice_exchange_days' => [1, 15],
            'payment_days' => [10, 25],
        ]);

        $this->assertSame([1, 15], $partner->fresh()->invoice_exchange_days);
        $this->assertSame([10, 25], $partner->fresh()->payment_days);
    }

    public function test_an_impossible_exchange_day_is_refused(): void
    {
        $this->expectException(InvalidPartnerRoles::class);
        $this->expectExceptionMessageMatches('/between 1 and 31/');

        BusinessPartner::factory()->create(['invoice_exchange_days' => [1, 45]]);
    }

    public function test_a_consignment_warehouse_needs_the_partner_it_belongs_to(): void
    {
        $this->expectException(InvalidWarehouseType::class);
        $this->expectExceptionMessageMatches('/needs the partner/');

        Warehouse::factory()->create(['warehouse_type' => 'consignment_in']);
    }

    public function test_an_ordinary_warehouse_has_no_partner(): void
    {
        $partner = BusinessPartner::factory()->create(['is_principal' => true]);

        $this->expectException(InvalidWarehouseType::class);
        $this->expectExceptionMessageMatches('/holds our own goods/');

        Warehouse::factory()->create(['warehouse_type' => 'damaged', 'partner_id' => $partner->id]);
    }

    public function test_the_two_consignment_directions_are_told_apart(): void
    {
        $principal = BusinessPartner::factory()->create(['is_principal' => true]);
        $outlet = BusinessPartner::factory()->create(['is_customer' => true]);

        $inbound = Warehouse::factory()->create(['warehouse_type' => 'consignment_in', 'partner_id' => $principal->id]);
        $outbound = Warehouse::factory()->create(['warehouse_type' => 'consignment_out', 'partner_id' => $outlet->id]);

        $this->assertTrue($inbound->holdsConsignedStock());
        $this->assertFalse($inbound->isOutletPlacement());
        // Goods placed with an outlet are still ours until the settlement.
        $this->assertFalse($outbound->holdsConsignedStock());
        $this->assertTrue($outbound->isOutletPlacement());
    }

    public function test_an_unknown_warehouse_type_is_refused(): void
    {
        $this->expectException(InvalidWarehouseType::class);

        Warehouse::factory()->create(['warehouse_type' => 'overflow']);
    }

    public function test_picking_order_follows_the_rack_walk_and_puts_unsequenced_bays_last(): void
    {
        $warehouse = Warehouse::factory()->create();
        foreach ([['A-01-03', 30], ['A-01-01', 10], ['Z-99-99', null], ['A-01-02', 20]] as [$code, $sequence]) {
            WarehouseLocation::create([
                'warehouse_id' => $warehouse->id, 'code' => $code, 'pick_sequence' => $sequence,
            ]);
        }

        $this->assertSame(
            ['A-01-01', 'A-01-02', 'A-01-03', 'Z-99-99'],
            WarehouseLocation::where('warehouse_id', $warehouse->id)->inPickOrder()->pluck('code')->all(),
        );
    }
}
