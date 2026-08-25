<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema section 2 — contracts, brands, products.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A principal may hold several concurrent contracts across territories.
        // Renewals are new rows, never edits to the old row.
        Schema::create('distribution_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('principal_id')->constrained('business_partners');
            $table->string('contract_number')->nullable();
            $table->foreignUuid('territory_id')->nullable()->constrained();
            $table->boolean('exclusive')->default(false);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->money('monthly_target')->nullable();
            $table->unsignedInteger('payment_terms_days')->nullable();
            $table->money('credit_limit')->nullable();
            $table->auditable();
            $table->index(['principal_id', 'starts_on']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NULL = unbranded / generic.
            $table->foreignUuid('principal_id')->nullable()->constrained('business_partners');
            $table->string('code')->unique();
            $table->string('name');
            $table->string('logo_url')->nullable();
            $table->boolean('active')->default(true);
            $table->auditable();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('name');
            $table->auditable();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('categories');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('brand_id')->nullable()->constrained();
            $table->foreignUuid('category_id')->nullable()->constrained();
            $table->string('sku')->unique();
            $table->string('name');
            // Smallest unit, e.g. 'PCS'. All stock is stored in this unit, and
            // it is locked once the product has movement history.
            $table->string('base_uom');

            // Batch and expiry.
            $table->boolean('track_batch')->default(false);
            $table->boolean('track_expiry')->default(false);
            $table->string('issue_method')->default('fefo');   // 'fefo' | 'fifo' | 'any'
            $table->unsignedInteger('shelf_life_days')->nullable();
            // Reject inbound goods below this percentage of shelf life remaining.
            $table->percent('min_life_on_receipt_pct')->nullable();
            // Remove batches from the picking pool below this many days remaining.
            $table->unsignedInteger('min_life_on_ship_days')->nullable();

            // Physical.
            $table->decimal('weight_grams', 12, 2)->nullable();
            $table->decimal('volume_cm3', 12, 2)->nullable();

            // NULL inherits company_settings.default_vat_percent. The schema doc
            // hardcoded 11; PPN has since moved for some goods, so the rate lives
            // in settings and this column is an override, not a copy.
            $table->percent('vat_percent')->nullable();
            $table->qty('reorder_point')->nullable();
            $table->boolean('active')->default(true);
            $table->auditable();

            // F-31: product search must match code, name, and barcode as you type.
            $table->index(['active', 'name']);
        });

        // Unit conversion: carton -> pack -> piece.
        Schema::create('product_uoms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('uom');                          // 'CARTON'
            $table->qty('qty_in_base');                     // 1 CARTON = 144 PCS
            $table->string('barcode')->nullable()->index();
            $table->boolean('default_for_purchase')->default(false);
            $table->boolean('default_for_sale')->default(false);
            $table->auditable();
            $table->unique(['product_id', 'uom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_uoms');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('distribution_contracts');
    }
};
