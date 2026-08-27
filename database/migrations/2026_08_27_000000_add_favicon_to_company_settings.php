<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not in the schema doc, which has no branding beyond `logo_url`.
 *
 * A separate column rather than reusing the logo: a browser tab renders this at
 * sixteen pixels, and a wide wordmark logo scaled to that is an unreadable
 * smear. Clients who have no square mark still get something sensible, because
 * the logo is used as the fallback when this is empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('favicon_url')->nullable()->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('favicon_url');
        });
    }
};
