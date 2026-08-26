<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Masters\Models\Product;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PurchaseOrderLine extends DomainModel
{
    protected $table = 'purchase_order_lines';

    protected function casts(): array
    {
        return [
            'unit_price' => DecimalCast::class.':2',
            'discount_amount' => DecimalCast::class.':2',
            'line_total' => DecimalCast::class.':2',
            'discount_percent' => DecimalCast::class.':2',
            'qty_in_base' => DecimalCast::class.':4',
            'qty' => DecimalCast::class.':4',
            'qty_base' => DecimalCast::class.':4',
            'qty_received' => DecimalCast::class.':4',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function goodsReceiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'purchase_order_line_id');
    }
}
