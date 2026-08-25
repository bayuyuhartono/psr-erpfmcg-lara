<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema section 9 — accounting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            // 'asset' | 'liability' | 'equity' | 'revenue' | 'expense'
            $table->string('account_type');
            $table->uuid('parent_id')->nullable()->index();
            $table->boolean('is_cash_account')->default(false);
            $table->boolean('active')->default(true);
            $table->auditable();
        });

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('chart_of_accounts');
        });

        // Which accounts each document type debits and credits. Configurable
        // per client, so no account code is ever hardcoded in posting logic.
        Schema::create('journal_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // 'goods_receipt' | 'delivery' | 'sales_invoice' | 'payment'
            $table->string('document_type');
            // 'inventory' | 'cogs' | 'revenue' | 'receivable' | 'vat_out'
            $table->string('posting_key');
            $table->foreignUuid('account_id')->constrained('chart_of_accounts');
            $table->auditable();
            $table->unique(['document_type', 'posting_key']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            $table->date('entry_date');
            $table->text('description')->nullable();
            // Source document type, 'manual' for adjustments.
            $table->string('ref_type')->nullable();
            $table->uuid('ref_id')->nullable();
            // draft | posted | reversed
            $table->string('status')->default('draft');
            $table->auditable();
            $table->index(['ref_type', 'ref_id']);
            $table->index(['status', 'entry_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('chart_of_accounts');
            $table->foreignUuid('partner_id')->nullable()->constrained('business_partners');
            $table->foreignUuid('branch_id')->nullable()->constrained();
            $table->money('debit')->default(0);
            $table->money('credit')->default(0);
            $table->text('description')->nullable();
            $table->auditable();
            $table->index(['account_id', 'journal_entry_id']);
        });

        // A line is a debit or a credit, never both.
        DB::statement(<<<'SQL'
            ALTER TABLE journal_lines
            ADD CONSTRAINT journal_lines_single_side
            CHECK (debit = 0 OR credit = 0)
        SQL);

        // Once a period is closed, no backdated transaction may post into it (F-60).
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status')->default('open');   // 'open' | 'closed'
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->auditable();
            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('journal_mappings');
        Schema::dropIfExists('chart_of_accounts');
    }
};
