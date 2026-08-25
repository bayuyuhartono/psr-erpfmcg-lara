<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class CompanySettingsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('company_settings')->updateOrInsert(
            ['id' => '00000000-0000-0000-0000-000000000001'],
            ['legal_name' => 'FMCG Distributor', 'default_vat_percent' => '11.00', 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
