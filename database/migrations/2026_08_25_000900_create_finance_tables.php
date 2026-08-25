<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema section 8 — receivables, payables, payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Invoice exchange: the batch of invoices handed to a customer on their
        // scheduled day (F-50). The schedule itself lives on business_partners.
        Schema::create('invoice_exchanges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            $table->foreignUuid('customer_id')->constrained('business_partners');
            $table->date('exchange_date');
            // Drives collection reminders.
            $table->date('promised_payment_date')->nullable();
            $table->money('total_amount')->default(0);
            $table->string('received_by')->nullable();
            // draft | handed_over | settled
            $table->string('status')->default('draft');
            $table->auditable();
            $table->index(['customer_id', 'status']);
            $table->index(['status', 'promised_payment_date']);
        });

        Schema::create('invoice_exchange_lines', function (Blueprint $table) {
            $table->foreignUuid('exchange_id')->constrained('invoice_exchanges')->cascadeOnDelete();
            $table->foreignUuid('sales_invoice_id')->constrained();
            $table->primary(['exchange_id', 'sales_invoice_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            // 'inbound' from a customer, 'outbound' to a supplier.
            $table->string('direction');
            $table->foreignUuid('partner_id')->constrained('business_partners');
            $table->date('payment_date');
            $table->string('method');          // 'cash' | 'transfer' | 'cheque'
            $table->money('amount');
            // Foreign key deferred: chart_of_accounts is created in section 9.
            $table->uuid('cash_account_id')->nullable()->index();
            $table->foreignUuid('collected_by')->nullable()->constrained('users');
            $table->string('status')->default('draft');   // draft | posted | cancelled
            $table->auditable();
            $table->index(['partner_id', 'direction', 'status']);
        });

        // One payment may close many invoices; one invoice may be paid in
        // instalments. An unallocated remainder is held as an advance (F-52).
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sales_invoice_id')->nullable()->constrained();
            $table->foreignUuid('supplier_invoice_id')->nullable()->constrained();
            $table->money('amount');
            $table->auditable();
        });

        // The schema doc left both invoice columns nullable with nothing
        // stopping an allocation that points at neither, or at one of each.
        DB::statement(<<<'SQL'
            ALTER TABLE payment_allocations
            ADD CONSTRAINT payment_allocations_one_invoice
            CHECK ((sales_invoice_id IS NULL) <> (supplier_invoice_id IS NULL))
        SQL);

        // Postdated cheques. Not cash until cleared (F-51).
        Schema::create('postdated_cheques', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->nullable()->constrained();
            $table->foreignUuid('partner_id')->constrained('business_partners');
            $table->string('cheque_number');
            $table->string('bank_name')->nullable();
            $table->date('received_on');
            $table->date('due_on');
            $table->money('amount');
            // held | deposited | cleared | bounced
            $table->string('status')->default('held');
            $table->date('resolved_on')->nullable();
            $table->text('bounce_reason')->nullable();
            $table->auditable();
            $table->index(['status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postdated_cheques');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_exchange_lines');
        Schema::dropIfExists('invoice_exchanges');
    }
};
