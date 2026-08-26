<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not in the schema doc. F-11 blocks a supplier invoice whose variance against
 * the order and the receipt is "above a tolerance threshold", without saying
 * where the threshold lives. It is per-client data, not code — one distributor
 * argues over five hundred rupiah and another writes it off.
 *
 * Two thresholds because one is always wrong somewhere: a percentage alone
 * waves through millions on a large invoice, and a flat amount alone blocks
 * rounding on a small one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->percent('match_tolerance_percent')->default('2.00')->after('default_vat_percent');
            $table->money('match_tolerance_amount')->default('50000.00')->after('match_tolerance_percent');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['match_tolerance_percent', 'match_tolerance_amount']);
        });
    }
};
