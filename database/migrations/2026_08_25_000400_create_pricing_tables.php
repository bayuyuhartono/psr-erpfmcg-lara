<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema section 3 — pricing, discounts, principal programmes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('active')->default(true);
            $table->auditable();
        });

        // Deferred from section 1, now that price_lists exists.
        Schema::table('customer_tiers', function (Blueprint $table) {
            $table->foreign('price_list_id')->references('id')->on('price_lists');
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained();
            $table->string('uom');
            $table->qty('min_qty')->default(0);      // volume pricing bands
            $table->money('unit_price');
            $table->auditable();
            $table->unique(['price_list_id', 'product_id', 'uom', 'min_qty'], 'price_list_items_unique');
        });

        // Tiered discounts compound sequentially: 5% then 3% then 2%,
        // never summed to 10%. `sequence` drives the order of application.
        Schema::create('discount_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('scope');        // 'product' | 'brand' | 'category' | 'all'
            $table->uuid('scope_id')->nullable();
            $table->string('target');       // 'customer' | 'tier' | 'territory' | 'all'
            $table->uuid('target_id')->nullable();
            $table->qty('min_qty')->nullable();
            $table->money('min_amount')->nullable();
            $table->percent('percent')->nullable();
            $table->money('amount')->nullable();
            $table->unsignedInteger('sequence')->default(1);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->auditable();
            $table->index(['scope', 'scope_id']);
            $table->index(['target', 'target_id']);
        });

        // Buy-get promotions: buy 10 cartons, get 1 free.
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignUuid('buy_product_id')->nullable()->constrained('products');
            $table->foreignUuid('buy_brand_id')->nullable()->constrained('brands');
            $table->qty('buy_qty');
            $table->string('buy_uom');
            $table->foreignUuid('get_product_id')->constrained('products');
            $table->qty('get_qty');
            $table->string('get_uom');
            $table->qty('max_per_order')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->auditable();
            $table->index(['starts_on', 'ends_on']);
        });

        // The schema doc left both buy_ columns nullable with nothing to stop a
        // promotion qualifying on neither, or on both at once.
        DB::statement(<<<'SQL'
            ALTER TABLE promotions
            ADD CONSTRAINT promotions_one_buy_target
            CHECK ((buy_product_id IS NULL) <> (buy_brand_id IS NULL))
        SQL);

        Schema::create('rebate_programs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('principal_id')->constrained('business_partners');
            // NULL = across all of the principal's brands.
            $table->foreignUuid('brand_id')->nullable()->constrained();
            $table->string('name');
            $table->string('basis');        // 'sell_in' | 'sell_out'
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('open');   // open | claimed | settled
            $table->auditable();
            $table->index(['principal_id', 'status']);
        });

        Schema::create('rebate_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained('rebate_programs')->cascadeOnDelete();
            $table->money('min_amount')->nullable();
            $table->qty('min_qty')->nullable();
            $table->percent('rebate_percent')->nullable();
            $table->money('rebate_amount')->nullable();
            $table->auditable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_tiers', function (Blueprint $table) {
            $table->dropForeign(['price_list_id']);
        });

        Schema::dropIfExists('rebate_tiers');
        Schema::dropIfExists('rebate_programs');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('discount_rules');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
