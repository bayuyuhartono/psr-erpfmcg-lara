<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not in the schema doc. Reversals there are described only as "new negative
 * rows", which leaves nothing to say which row cancels which — and nothing to
 * stop a document being reversed twice.
 *
 * This column names the row being cancelled. The unique index makes a second
 * reversal of the same row impossible at the database level rather than
 * dependent on a check-then-write that two concurrent cancellations would both
 * pass. Nullable, and NULL for every original posting: MySQL allows many NULLs
 * in a unique index, which is exactly the shape wanted here.
 *
 * The table stays append-only. Nothing here updates a ledger row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->unsignedBigInteger('reverses_ledger_id')->nullable()->after('ref_line_id');
            $table->foreign('reverses_ledger_id')->references('id')->on('stock_ledger');
            $table->unique('reverses_ledger_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropForeign(['reverses_ledger_id']);
            $table->dropUnique(['reverses_ledger_id']);
            $table->dropColumn('reverses_ledger_id');
        });
    }
};
