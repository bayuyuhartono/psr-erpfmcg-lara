<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\WarehouseLocation;
use App\Domain\Masters\Models\Product;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class GoodsReceiptLine extends DomainModel
{
    protected $table = 'goods_receipt_lines';

    protected function casts(): array
    {
        return [
            'qty_in_base' => DecimalCast::class.':4',
            'qty' => DecimalCast::class.':4',
            'qty_base' => DecimalCast::class.':4',
            'qty_rejected' => DecimalCast::class.':4',
            'unit_cost' => DecimalCast::class.':4',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function supplierInvoiceLines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class, 'goods_receipt_line_id');
    }
}
