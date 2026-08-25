<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema section 1 — business partners.
 *
 * One table, multiple roles. A company can be a principal and a supplier at
 * once, or a supplier and a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('territories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('name');
            $table->auditable();
        });

        Schema::table('territories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('territories');
        });

        Schema::create('customer_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();     // 'wholesale', 'retail', 'modern trade'
            // Foreign key deferred: price_lists is created in section 3.
            $table->uuid('price_list_id')->nullable()->index();
            $table->auditable();
        });

        Schema::create('salespeople', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignUuid('territory_id')->nullable()->constrained();
            $table->string('sales_type')->nullable();   // 'canvas' | 'taking_order' | 'motorist'
            $table->boolean('active')->default(true);
            $table->auditable();
        });

        Schema::create('business_partners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();

            $table->boolean('is_supplier')->default(false);
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_principal')->default(false);

            // Customer-only fields.
            $table->foreignUuid('tier_id')->nullable()->constrained('customer_tiers');
            $table->foreignUuid('salesperson_id')->nullable()->constrained('salespeople');
            $table->foreignUuid('territory_id')->nullable()->constrained('territories');
            $table->money('credit_limit')->default(0);
            $table->unsignedInteger('payment_terms_days')->default(0);
            $table->boolean('on_hold')->default(false);
            $table->text('hold_reason')->nullable();
            // Postgres int[] has no MySQL equivalent; JSON arrays of day-of-month
            // integers. [1, 15] = invoices exchanged on the 1st and the 15th.
            $table->json('invoice_exchange_days')->nullable();
            $table->json('payment_days')->nullable();

            $table->boolean('active')->default(true);
            $table->auditable();

            $table->index(['is_customer', 'active']);
            $table->index(['is_supplier', 'active']);
            $table->index(['is_principal', 'active']);
            $table->index('name');
        });

        Schema::create('partner_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->constrained('business_partners')->cascadeOnDelete();
            $table->string('address_type');        // 'billing' | 'shipping'
            $table->string('recipient_name')->nullable();
            $table->text('address');
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->auditable();
            $table->index(['partner_id', 'address_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_addresses');
        Schema::dropIfExists('business_partners');
        Schema::dropIfExists('salespeople');
        Schema::dropIfExists('customer_tiers');
        Schema::dropIfExists('territories');
    }
};
