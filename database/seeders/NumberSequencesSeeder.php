<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Installation-wide document sequences. Per-module, per-branch sequences (PO,
 * GR, SO, DO, SI) are configured with the client's branches, since their
 * prefixes are client conventions; DocumentNumberer rolls each into the next
 * period on its own.
 */
final class NumberSequencesSeeder extends Seeder
{
    public function run(): void
    {
        $sequences = [
            ['JE', 'JE/{YY}{MM}/'],
            ['PO', 'PO/{YY}{MM}/'],
            ['GR', 'GR/{YY}{MM}/'],
            ['PR', 'PR/{YY}{MM}/'],
            ['CLM', 'CLM/{YY}{MM}/'],
            ['SC', 'SC/{YY}{MM}/'],
            ['ADJ', 'ADJ/{YY}{MM}/'],
            ['TRF', 'TRF/{YY}{MM}/'],
            ['SO', 'SO/{YY}{MM}/'],
            ['DO', 'DO/{YY}{MM}/'],
            ['INV', 'INV/{YY}{MM}/'],
            ['SR', 'SR/{YY}{MM}/'],
            ['RCP', 'RCP/{YY}{MM}/'],
            ['PMT', 'PMT/{YY}{MM}/'],
            ['EXC', 'EXC/{YY}{MM}/'],
        ];

        foreach ($sequences as [$type, $prefix]) {
            $key = ['branch_id' => null, 'document_type' => $type, 'period' => now()->format('Y-m')];
            $existing = DB::table('number_sequences')->where($key)->first();
            DB::table('number_sequences')->updateOrInsert($key, [
                'id' => $existing?->id ?? (string) Str::uuid7(),
                'prefix' => $prefix,
                'last_number' => $existing?->last_number ?? 0,
                'created_at' => $existing?->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }
}
