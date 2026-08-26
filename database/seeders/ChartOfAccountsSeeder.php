<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['1101', 'Cash and Bank', 'asset', true],
            ['1102', 'Trade Receivables', 'asset', false],
            ['1103', 'Inventory', 'asset', false],
            ['2101', 'Trade Payables', 'liability', false],
            ['2102', 'VAT Payable', 'liability', false],
            ['3101', 'Retained Earnings', 'equity', false],
            // Balances the opening stock import. Without it the whole opening
            // inventory lands in an expense variance in month one.
            ['3102', 'Opening Balance Equity', 'equity', false],
            ['4101', 'Sales Revenue', 'revenue', false],
            ['5101', 'Cost of Goods Sold', 'expense', false],
            ['5102', 'Inventory Variance', 'expense', false],
        ];

        foreach ($accounts as [$code, $name, $type, $cash]) {
            $existing = DB::table('chart_of_accounts')->where('code', $code)->first();
            DB::table('chart_of_accounts')->updateOrInsert(['code' => $code], [
                'id' => $existing?->id ?? (string) Str::uuid7(), 'name' => $name,
                'account_type' => $type, 'is_cash_account' => $cash, 'active' => true,
                'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
            ]);
        }
    }
}
