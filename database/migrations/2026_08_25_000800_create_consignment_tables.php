<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema section 7 — consignment, in both directions.
 *
 * Outbound consignment works as a warehouse transfer, not a sale. Goods move to
 * a virtual warehouse representing the outlet, so they stay visible in
 * inventory. Revenue, cost of goods, and the receivable only appear when the
 * settlement is raised.
 *
 * Inbound consignment works through owner_id on the ledger. The goods sit in a
 * real warehouse but belong to the principal, so they are excluded from
 * inventory valuation. The payable appears only at settlement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignment_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            // 'inbound' settles to the principal, 'outbound' settles from the outlet.
            $table->string('direction');
            $table->foreignUuid('partner_id')->constrained('business_partners');
            $table->foreignUuid('warehouse_id')->constrained();
            $table->date('period_start');
            $table->date('period_end');
            $table->money('total_amount')->default(0);
            $table->foreignUuid('sales_invoice_id')->nullable()->constrained();
            $table->foreignUuid('supplier_invoice_id')->nullable()->constrained();
            // draft | confirmed | invoiced | cancelled
            $table->string('status')->default('draft');
            $table->auditable();
            $table->index(['partner_id', 'direction', 'status']);
        });

        Schema::create('consignment_settlement_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('settlement_id')->constrained('consignment_settlements')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained();
            $table->foreignUuid('batch_id')->nullable()->constrained('stock_batches');
            $table->qty('qty_placed');      // opening quantity for the period
            $table->qty('qty_sold');
            $table->qty('qty_returned')->default(0);
            $table->qty('qty_remaining');   // carried into the next period
            $table->money('unit_price');
            $table->money('line_total');
            $table->auditable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_settlement_lines');
        Schema::dropIfExists('consignment_settlements');
    }
};
