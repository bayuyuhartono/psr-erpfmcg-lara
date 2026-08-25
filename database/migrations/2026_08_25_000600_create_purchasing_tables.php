<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema section 5 — purchasing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->nullable()->constrained();
            $table->string('document_number')->unique();
            $table->foreignUuid('supplier_id')->constrained('business_partners');
            $table->foreignUuid('principal_id')->nullable()->constrained('business_partners');
            $table->foreignUuid('warehouse_id')->constrained();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->unsignedInteger('payment_terms_days')->nullable();
            $table->money('subtotal')->default(0);
            $table->money('discount_amount')->default(0);
            $table->money('vat_amount')->default(0);
            $table->money('total')->default(0);
            // draft | pending_approval | approved | partially_received | closed | cancelled
            $table->string('status')->default('draft');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('note')->nullable();
            $table->auditable();
            $table->index(['supplier_id', 'status']);
            $table->index(['status', 'order_date']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained();
            $table->string('uom');
            // Conversion snapshot at time of order. Never re-read from the
            // master: editing a product_uom must not restate old documents.
            $table->qty('qty_in_base');
            $table->qty('qty');
            $table->qty('qty_base');
            $table->qty('qty_received')->default(0);
            $table->money('unit_price');
            $table->percent('discount_percent')->default(0);
            $table->money('discount_amount')->default(0);
            $table->money('line_total');
            $table->auditable();
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            // NULL allowed: receipt without a purchase order.
            $table->foreignUuid('purchase_order_id')->nullable()->constrained();
            $table->foreignUuid('supplier_id')->constrained('business_partners');
            $table->foreignUuid('warehouse_id')->constrained();
            // Non-null for inbound consignment: goods held but not owned.
            $table->foreignUuid('owner_id')->nullable()->constrained('business_partners');
            $table->date('receipt_date');
            $table->string('supplier_do_number')->nullable();
            $table->string('status')->default('draft');   // draft | posted | cancelled
            $table->auditable();
            $table->index(['status', 'receipt_date']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_order_line_id')->nullable()->constrained();
            $table->foreignUuid('product_id')->constrained();
            $table->foreignUuid('batch_id')->nullable()->constrained('stock_batches');
            $table->foreignUuid('location_id')->nullable()->constrained('warehouse_locations');
            $table->string('uom');
            $table->qty('qty_in_base');
            $table->qty('qty');
            $table->qty('qty_base');
            $table->qty('qty_rejected')->default(0);
            // 'short_shelf_life' | 'damaged' | 'wrong_item'
            $table->string('rejection_reason')->nullable();
            $table->cost('unit_cost');
            $table->auditable();
        });

        // Three-way match: PO vs receipt vs invoice, before posting is permitted.
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            $table->string('supplier_ref')->nullable();
            $table->string('tax_invoice_number')->nullable();
            $table->foreignUuid('supplier_id')->constrained('business_partners');
            $table->date('invoice_date');
            $table->date('due_date');
            $table->money('subtotal');
            $table->money('vat_amount');
            $table->money('total');
            $table->money('paid_amount')->default(0);
            // draft | posted | paid | cancelled
            $table->string('status')->default('draft');
            $table->auditable();
            $table->index(['supplier_id', 'status', 'due_date']);
        });

        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('goods_receipt_line_id')->nullable()->constrained();
            $table->foreignUuid('product_id')->nullable()->constrained();
            $table->qty('qty')->nullable();
            $table->money('unit_price')->nullable();
            $table->money('line_total');
            $table->auditable();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            $table->foreignUuid('supplier_id')->constrained('business_partners');
            $table->foreignUuid('warehouse_id')->constrained();
            $table->foreignUuid('goods_receipt_id')->nullable()->constrained();
            $table->date('return_date');
            $table->text('reason')->nullable();
            $table->string('status')->default('draft');
            $table->auditable();
        });

        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained();
            $table->foreignUuid('batch_id')->nullable()->constrained('stock_batches');
            $table->qty('qty_base');
            $table->money('unit_price');
            $table->auditable();
        });

        // Claims against principals for damaged or expired goods (F-23).
        Schema::create('principal_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            $table->foreignUuid('principal_id')->constrained('business_partners');
            $table->date('claim_date');
            $table->string('claim_type');       // 'expired' | 'damaged' | 'recall'
            $table->money('total_amount')->default(0);
            $table->money('approved_amount')->nullable();
            // draft | submitted | approved | rejected | settled
            $table->string('status')->default('draft');
            $table->auditable();
            $table->index(['principal_id', 'status']);
        });

        Schema::create('principal_claim_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('claim_id')->constrained('principal_claims')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained();
            $table->foreignUuid('batch_id')->nullable()->constrained('stock_batches');
            $table->qty('qty_base');
            $table->money('unit_price');
            $table->auditable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('principal_claim_lines');
        Schema::dropIfExists('principal_claims');
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('supplier_invoice_lines');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
