<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class JournalMappingsSeeder extends Seeder
{
    public function run(): void
    {
        // A starting point, not a fixed chart: every client remaps these. The
        // document types of stock movements are the ledger's ref_type values,
        // because that is what StockPosting looks up.
        $mappings = [
            'goods_receipt' => ['inventory' => '1103', 'payable' => '2101'],
            'purchase_return' => ['inventory' => '1103', 'payable' => '2101'],
            'delivery' => ['cogs' => '5101', 'inventory' => '1103'],
            'sales_return' => ['cogs' => '5101', 'inventory' => '1103'],
            'adjustment' => ['inventory' => '1103', 'variance' => '5102'],
            'consignment_settlement' => ['inventory' => '1103', 'payable' => '2101'],
            'sales_invoice' => ['receivable' => '1102', 'revenue' => '4101', 'vat_out' => '2102'],
            'payment' => ['cash' => '1101', 'receivable' => '1102'],
        ];

        foreach ($mappings as $type => $keys) {
            foreach ($keys as $key => $accountCode) {
                $accountId = DB::table('chart_of_accounts')->where('code', $accountCode)->value('id');
                $existing = DB::table('journal_mappings')->where(['document_type' => $type, 'posting_key' => $key])->first();
                DB::table('journal_mappings')->updateOrInsert(
                    ['document_type' => $type, 'posting_key' => $key],
                    ['id' => $existing?->id ?? (string) Str::uuid7(), 'account_id' => $accountId,
                        'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()],
                );
            }
        }
    }
}
