<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not in the schema doc. F-30 asks for the hold reason to be shown "clearly
 * along with the numbers", and `hold_reason` carries only a coded value —
 * `over_credit_limit` says nothing about by how much.
 *
 * Recomputing the figures when the order is opened would show today's
 * position, not the one that triggered the hold. If the customer pays
 * something down in between, the supervisor deciding whether to release would
 * be reading numbers that no longer explain the decision in front of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->text('hold_detail')->nullable()->after('hold_reason');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('hold_detail');
        });
    }
};
