<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema section 0 — system and organisation.
 *
 * Single-tenant by decision: each client gets their own database, so no
 * table carries a company_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('address')->nullable();
            $table->boolean('active')->default(true);
            $table->auditable();
        });

        // Single row, guarded by a CHECK on the primary key.
        Schema::create('company_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legal_name');
            $table->string('tax_id')->nullable();
            $table->text('address')->nullable();
            // Costing is moving average only. There is deliberately no
            // costing_method column: a setting that accepts a single value
            // reads as a choice that does not exist. FIFO would arrive as a
            // cost-layers table plus this column, additively.
            $table->percent('default_vat_percent')->default(11);
            $table->string('logo_url')->nullable();
            $table->auditable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE company_settings
            ADD CONSTRAINT company_settings_single_row
            CHECK (id = '00000000-0000-0000-0000-000000000001')
        SQL);

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->auditable();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            // 'sales_order.approve', 'product.view_cost'
            $table->string('permission');
            // Optional ceiling for the permission, e.g. the maximum discount
            // percent this role may approve. NULL means no ceiling.
            $table->money('threshold')->nullable();
            $table->primary(['role_id', 'permission']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });

        // Document numbers come from here, under a row lock. Never COUNT(*).
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->nullable()->constrained();
            $table->string('document_type');          // 'PO', 'GR', 'SO', 'DO', 'SI'
            $table->string('prefix');                 // 'SO/{BRANCH}/{YY}{MM}/'
            $table->string('period');                 // '2026-08', for monthly reset
            $table->unsignedBigInteger('last_number')->default(0);
            $table->auditable();
            $table->unique(['branch_id', 'document_type', 'period']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('user_id')->nullable();
            $table->string('table_name');
            $table->uuid('record_id');
            // 'insert' | 'update' | 'delete' | 'approve' | 'release_hold'
            $table->string('action');
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['table_name', 'record_id']);
            $table->index('user_id');
        });

        Schema::create('licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('license_key');
            $table->date('expires_on')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->json('enabled_modules')->nullable();
            $table->timestamp('issued_at')->useCurrent();
            $table->auditable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('company_settings');
        Schema::dropIfExists('branches');
    }
};
