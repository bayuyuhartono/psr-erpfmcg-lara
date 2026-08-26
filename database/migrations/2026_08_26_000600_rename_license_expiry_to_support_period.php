<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deviates from the schema doc, which has `licenses.expires_on`.
 *
 * The product is **sold, not rented**. A distributor who has paid for the
 * software owns it, and it does not stop working — not when maintenance
 * lapses, not ever. Software that switches itself off is holding a business's
 * receivables ledger hostage, and this one runs on their own server.
 *
 * `expires_on` was a booby trap under that model: the obvious thing to do with
 * a column of that name is block on it, and the next person to see it would
 * have been right to. What the date actually means is when support and updates
 * end, so that is what it is now called.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->renameColumn('expires_on', 'supported_until');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->renameColumn('supported_until', 'expires_on');
        });
    }
};
