<?php

namespace App\Domain\Sales\Models;

use App\Domain\Masters\Models\Product;
use App\Domain\Masters\Models\Promotion;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesOrderLine extends DomainModel
{
    protected function casts(): array
    {
        return [
            'unit_price' => DecimalCast::class.':2',
            'discount_amount' => DecimalCast::class.':2',
            'line_total' => DecimalCast::class.':2',
            'discount1_percent' => DecimalCast::class.':2',
            'discount2_percent' => DecimalCast::class.':2',
            'discount3_percent' => DecimalCast::class.':2',
            'qty_in_base' => DecimalCast::class.':4',
            'qty' => DecimalCast::class.':4',
            'qty_base' => DecimalCast::class.':4',
            'qty_shipped' => DecimalCast::class.':4',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class, 'promotion_id');
    }

    public function deliveryLines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class, 'sales_order_line_id');
    }
}
