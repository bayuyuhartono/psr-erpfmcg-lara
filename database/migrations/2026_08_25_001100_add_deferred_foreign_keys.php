<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema section 10 — deferred constraints.
 *
 * Forward references, added once every table exists. The remaining deferred
 * keys from the schema doc (customer_tiers.price_list_id, and the
 * business_partners customer columns) were resolvable in dependency order and
 * are declared with their tables instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('cash_account_id')->references('id')->on('chart_of_accounts');
        });

        // Circular by nature: a settlement raises an invoice, and the invoice
        // records which settlement produced it.
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->foreign('settlement_id')->references('id')->on('consignment_settlements');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropForeign(['settlement_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cash_account_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
        });
    }
};
