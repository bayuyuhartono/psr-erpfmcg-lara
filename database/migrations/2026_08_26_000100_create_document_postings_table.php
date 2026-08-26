<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not in the schema doc. One row per document that has posted, claimed inside
 * the posting transaction.
 *
 * The unique key is the whole point: a double-submitted goods receipt loses the
 * race at the database rather than passing a check-then-write that two
 * concurrent requests would both clear. Rolling back a failed posting releases
 * the claim with it, so a corrected retry is free.
 *
 * Append-only like `stock_ledger`, and for the same reason — no `auditable()`,
 * because an edit path here would be an edit path to "has this posted?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_postings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ref_type');
            $table->uuid('ref_id');
            $table->timestamp('posted_at')->useCurrent();
            $table->uuid('posted_by')->nullable();

            $table->unique(['ref_type', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_postings');
    }
};
