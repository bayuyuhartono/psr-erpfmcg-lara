<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema section 4 — warehouse and inventory.
 *
 * Two principles govern this section.
 *
 * The stock ledger is the single source of truth. Every movement writes a row
 * and nothing bypasses it. stock_balances is a cache that can be rebuilt from
 * the ledger at any time.
 *
 * Ownership is tracked separately from location. owner_id is NULL for goods the
 * distributor owns. Non-null means the goods sit in the warehouse but belong to
 * someone else, and must be excluded from inventory value on the balance sheet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->nullable()->constrained();
            $table->string('code')->unique();
            $table->string('name');
            // 'normal' | 'quarantine' | 'damaged' | 'consignment_in' | 'consignment_out'
            $table->string('warehouse_type')->default('normal');
            // consignment_in: the principal who owns the goods.
            // consignment_out: the outlet holding our goods.
            $table->foreignUuid('partner_id')->nullable()->constrained('business_partners');
            $table->boolean('active')->default(true);
            $table->auditable();
        });

        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('code');                            // 'A-01-03'
            $table->unsignedInteger('pick_sequence')->nullable();  // drives picking list sort order
            $table->auditable();
            $table->unique(['warehouse_id', 'code']);
        });

        Schema::create('stock_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained();
            $table->string('batch_number');
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->auditable();
            $table->unique(['product_id', 'batch_number']);
            // FEFO batch selection at picking.
            $table->index(['product_id', 'expiry_date']);
        });

        // Append-only. Never UPDATE, never DELETE. Reversals are new negative
        // rows. That rule is why this table has no updated_at or deleted_at:
        // giving it the standard audit columns would imply an edit path exists.
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('warehouse_id')->constrained();
            $table->foreignUuid('location_id')->nullable()->constrained('warehouse_locations');
            $table->foreignUuid('product_id')->constrained();
            $table->foreignUuid('batch_id')->nullable()->constrained('stock_batches');
            // NULL = owned by us.
            $table->foreignUuid('owner_id')->nullable()->constrained('business_partners');
            $table->qty('qty_base');            // positive in, negative out
            $table->cost('unit_cost');
            $table->money('value');
            // 'goods_receipt' | 'delivery' | 'adjustment' | 'transfer_out' | 'transfer_in'
            // | 'sales_return' | 'purchase_return' | 'consignment_settlement'
            $table->string('ref_type');
            $table->uuid('ref_id');
            $table->uuid('ref_line_id')->nullable();
            $table->date('transaction_date');
            $table->timestamp('created_at')->useCurrent();
            $table->uuid('created_by')->nullable();

            $table->index(['product_id', 'warehouse_id', 'transaction_date'], 'stock_ledger_product_wh_date');
            $table->index(['ref_type', 'ref_id']);
            // MySQL has no partial indexes; the Postgres original was
            // WHERE owner_id IS NOT NULL. A plain index costs a little more
            // space and answers the same consignment queries.
            $table->index('owner_id');
        });

        // Cache over stock_ledger, rebuildable at any time.
        //
        // The schema doc gave this table PRIMARY KEY (warehouse_id, product_id,
        // batch_id, owner_id), which cannot work: primary key columns are
        // implicitly NOT NULL, so a non-batch-tracked product that we own —
        // both columns NULL — could never hold a balance row. Instead the row
        // gets a surrogate key, and uniqueness is enforced over stored
        // generated columns that collapse NULL to a sentinel.
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_id')->constrained();
            $table->foreignUuid('product_id')->constrained();
            $table->foreignUuid('batch_id')->nullable()->constrained('stock_batches');
            $table->foreignUuid('owner_id')->nullable()->constrained('business_partners');
            $table->string('batch_key', 36)->storedAs("COALESCE(batch_id, '-')");
            $table->string('owner_key', 36)->storedAs("COALESCE(owner_id, '-')");
            $table->qty('qty_base')->default(0);
            $table->money('value')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(
                ['warehouse_id', 'product_id', 'batch_key', 'owner_key'],
                'stock_balances_unique'
            );
            // Balance-sheet queries filter owner_id IS NULL; see schema §11.
            $table->index(['product_id', 'owner_id']);
        });

        // Moving average cost, held per product. Lock this row with
        // SELECT ... FOR UPDATE inside the same transaction as the ledger write.
        Schema::create('product_costs', function (Blueprint $table) {
            $table->foreignUuid('product_id')->primary()->constrained();
            $table->cost('average_cost')->default(0);
            $table->qty('total_qty')->default(0);
            $table->money('total_value')->default(0);
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_id')->constrained();
            $table->foreignUuid('product_id')->constrained();
            // Quantity only, deliberately not batch-specific: batch is chosen
            // at picking by FEFO, not when the order is taken.
            $table->qty('qty_base');
            $table->string('ref_type');                  // 'sales_order'
            $table->uuid('ref_id');
            $table->string('status')->default('active'); // active | consumed | released
            $table->timestamp('expires_at');
            $table->auditable();

            $table->index(['product_id', 'warehouse_id', 'status'], 'stock_reservations_lookup');
            $table->index(['ref_type', 'ref_id']);
            // Drives the cleanup job for reservations left behind by
            // unprocessed orders.
            $table->index(['status', 'expires_at']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            $table->foreignUuid('from_warehouse_id')->constrained('warehouses');
            $table->foreignUuid('to_warehouse_id')->constrained('warehouses');
            $table->date('transfer_date');
            // 'rebalance' | 'consignment_placement' | 'quarantine'
            $table->string('transfer_reason')->nullable();
            // draft | shipped | received | cancelled
            $table->string('status')->default('draft');
            $table->auditable();
            $table->index(['status', 'transfer_date']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained();
            $table->foreignUuid('batch_id')->nullable()->constrained('stock_batches');
            $table->qty('qty_base');
            $table->qty('qty_received')->default(0);
            $table->auditable();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            $table->foreignUuid('warehouse_id')->constrained();
            $table->date('count_date');
            $table->string('count_type')->default('full');   // 'full' | 'cycle'
            $table->foreignUuid('scope_category_id')->nullable()->constrained('categories');
            // draft | counting | reviewed | posted
            $table->string('status')->default('draft');
            $table->auditable();
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained();
            $table->foreignUuid('batch_id')->nullable()->constrained('stock_batches');
            // Frozen when the count is created, so counting does not have to
            // halt warehouse operations.
            $table->qty('system_qty');
            $table->qty('counted_qty')->nullable();
            $table->qty('variance')->storedAs('counted_qty - system_qty');
            $table->text('note')->nullable();
            $table->auditable();
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->unique();
            $table->foreignUuid('warehouse_id')->constrained();
            $table->date('adjustment_date');
            // 'damage' | 'loss' | 'expired' | 'count_variance'
            $table->string('reason');
            $table->foreignUuid('count_id')->nullable()->constrained('stock_counts');
            $table->string('status')->default('draft');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->auditable();
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained();
            $table->foreignUuid('batch_id')->nullable()->constrained('stock_batches');
            $table->qty('qty_base');    // may be negative
            $table->auditable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('product_costs');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_ledger');
        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('warehouse_locations');
        Schema::dropIfExists('warehouses');
    }
};
