<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not in the schema doc. F-10 requires a mandatory reason when what arrived
 * differs from what was ordered, and there was nowhere to record one:
 * `rejection_reason` covers goods turned away at the door, which is a
 * different event from a supplier shipping 90 of the 100 they were asked for.
 *
 * `closed_short` is the other half. A line delivered short is not the same as
 * a line still awaiting the rest, and only the receiver knows which — without
 * it, an order the supplier will never complete stays open for ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->string('variance_reason')->nullable()->after('rejection_reason');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->boolean('closed_short')->default(false)->after('qty_received');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropColumn('variance_reason');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropColumn('closed_short');
        });
    }
};
