<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'Administrator' => ['*' => null],
            'Finance Manager' => ['accounting.view' => null, 'journal.post' => null, 'payment.approve' => null, 'accounting.period.close' => null, 'supplier_invoice.approve_variance' => null, 'supplier_invoice.cancel' => null],
            'Warehouse Supervisor' => ['inventory.view' => null, 'stock.adjust.approve' => null, 'stock.transfer.post' => null, 'goods_receipt.post' => null, 'goods_receipt.cancel' => null],
            // Tiered ceilings, the same mechanism as discount approval: a buyer
            // commits up to 50 million, anything larger goes to the manager.
            'Purchasing' => ['purchase_order.create' => null, 'purchase_order.approve' => '50000000.00'],
            'Purchasing Manager' => ['purchase_order.create' => null, 'purchase_order.approve' => null],
            'Sales Supervisor' => ['sales_order.approve' => null, 'discount.approve' => '20.00', 'product.view_cost' => null],
            'Sales Admin' => ['sales_order.create' => null, 'sales_order.view' => null, 'discount.approve' => '5.00'],
        ];

        foreach ($roles as $name => $permissions) {
            $role = DB::table('roles')->where('name', $name)->first();
            $roleId = $role?->id ?? (string) Str::uuid7();
            DB::table('roles')->updateOrInsert(['name' => $name], [
                'id' => $roleId, 'created_at' => $role?->created_at ?? now(), 'updated_at' => now(),
            ]);
            foreach ($permissions as $permission => $threshold) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission' => $permission],
                    ['threshold' => $threshold],
                );
            }
        }
    }
}
