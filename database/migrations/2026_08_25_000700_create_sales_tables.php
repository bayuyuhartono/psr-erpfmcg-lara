<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema section 6 — sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->nullable()->constrained();
            $table->string('document_number')->unique();
            $table->foreignUuid('customer_id')->constrained('business_partners');
            $table->foreignUuid('shipping_address_id')->nullable()->constrained('partner_addresses');
            $table->foreignUuid('salesperson_id')->nullable()->constrained('salespeople');
            $table->foreignUuid('warehouse_id')->constrained();
            $table->date('order_date');
            $table->date('requested_date')->nullable();
            $table->foreignUuid('price_list_id')->nullable()->constrained();
            $table->money('subtotal')->default(0);
            $table->money('discount_amount')->default(0);
            $table->money('vat_amount')->default(0);
            $table->money('total')->default(0);
            // draft | credit_hold | pending_approval | approved
            // | partially_shipped | closed | cancelled
            $table->string('status')->default('draft');
            // 'over_credit_limit' | 'overdue_invoices' | 'customer_blocked'
            $table->string('hold_reason')->nullable();
            $table->foreignUuid('released_by')->nullable()->constrained('users');
            $table->timestamp('released_at')->nullable();
            $table->string('source')->default('office');   // 'office' | 'mobile' | 'import'
            // When the order was captured on the salesperson's device, which may
            // be well before it reached the server.
            $table->timestamp('offline_created_at')->nullable();
            $table->auditable();

            $table->index(['customer_id', 'status']);
            $table->index(['status', 'order_date']);
            $table->index(['salesperson_id', 'order_date']);
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained();
            $table->string('uom');
            $table->qty('qty_in_base');       // conversion snapshot
            $table->qty('qty');
            $table->qty('qty_base');
            $table->qty('qty_shipped')->default(0);
            $table->money('unit_price');
            // Compounds sequentially: 5% then 3% then 2%, not summed to 10%.
            $table->percent('discount1_percent')->default(0);
            $table->percent('discount2_percent')->default(0);
            $table->percent('discount3_percent')->default(0);
            $table->money('discount_amount')->default(0);
            // Zero revenue, still carries cost out of stock.
            $table->boolean('is_bonus')->default(false);
            $table->foreignUuid('promotion_id')->nullable()->constrained();
            $table->money('line_total');
            $table->auditable();
            $table->index('product_id');
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            $table->foreignUuid('customer_id')->constrained('business_partners');
            $table->foreignUuid('shipping_address_id')->nullable()->constrained('partner_addresses');
            $table->foreignUuid('warehouse_id')->constrained();
            $table->date('delivery_date');
            $table->foreignUuid('driver_id')->nullable()->constrained('users');
            $table->string('vehicle_plate')->nullable();
            // draft | picking | loaded | in_transit | delivered | failed | cancelled
            $table->string('status')->default('draft');
            $table->auditable();
            $table->index(['status', 'delivery_date']);
        });

        // One delivery may cover several orders for the same customer (F-41).
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->foreignUuid('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sales_order_id')->constrained();
            $table->primary(['delivery_id', 'sales_order_id']);
        });

        Schema::create('delivery_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sales_order_line_id')->nullable()->constrained();
            $table->foreignUuid('product_id')->constrained();
            // Chosen by FEFO at picking. Batch lives here rather than on the
            // order line so the warehouse stays free to pick by nearest expiry.
            $table->foreignUuid('batch_id')->nullable()->constrained('stock_batches');
            $table->foreignUuid('location_id')->nullable()->constrained('warehouse_locations');
            $table->qty('qty_base');
            // Captured at proof of delivery, may be lower than shipped (F-42).
            $table->qty('qty_accepted')->nullable();
            $table->text('batch_override_reason')->nullable();
            $table->cost('unit_cost')->nullable();
            $table->auditable();
        });

        Schema::create('delivery_pods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_id')->constrained()->cascadeOnDelete();
            $table->timestamp('received_at');
            $table->string('recipient_name')->nullable();
            $table->string('photo_url')->nullable();
            $table->string('signature_url')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('outcome');       // 'full' | 'partial' | 'refused'
            $table->text('note')->nullable();
            $table->auditable();
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            $table->string('tax_invoice_number')->nullable();
            $table->foreignUuid('customer_id')->constrained('business_partners');
            $table->foreignUuid('delivery_id')->nullable()->constrained();
            // Foreign key deferred: set when raised from a consignment
            // settlement, which is created in section 7 and points back here.
            $table->uuid('settlement_id')->nullable()->index();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->money('subtotal');
            $table->money('discount_amount')->default(0);
            $table->money('vat_amount')->default(0);
            $table->money('total');
            $table->money('paid_amount')->default(0);
            // draft | posted | exchanged | partially_paid | paid | cancelled
            $table->string('status')->default('draft');
            $table->auditable();
            // Receivables aging (F-53) reads this index.
            $table->index(['customer_id', 'status', 'due_date']);
        });

        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('delivery_line_id')->nullable()->constrained();
            $table->foreignUuid('product_id')->constrained();
            $table->string('uom');
            $table->qty('qty');
            $table->money('unit_price');
            $table->money('discount_amount')->default(0);
            $table->money('line_total');
            $table->auditable();
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            $table->foreignUuid('customer_id')->constrained('business_partners');
            $table->foreignUuid('sales_invoice_id')->nullable()->constrained();
            $table->foreignUuid('warehouse_id')->constrained();
            $table->date('return_date');
            // 'damaged' | 'expired' | 'wrong_item' | 'unsold'
            $table->string('reason');
            // false routes the goods to the damaged warehouse
            $table->boolean('resellable')->default(true);
            $table->string('status')->default('draft');
            $table->auditable();
        });

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_return_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained();
            $table->foreignUuid('batch_id')->nullable()->constrained('stock_batches');
            $table->qty('qty_base');
            $table->money('unit_price');
            $table->auditable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('sales_invoice_lines');
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('delivery_pods');
        Schema::dropIfExists('delivery_lines');
        Schema::dropIfExists('delivery_orders');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
