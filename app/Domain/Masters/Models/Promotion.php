<?php

namespace App\Domain\Masters\Models;

use App\Domain\Sales\Models\SalesOrderLine;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Promotion extends DomainModel
{
    protected $table = 'promotions';

    protected function casts(): array
    {
        return [
            'buy_qty' => DecimalCast::class.':4',
            'get_qty' => DecimalCast::class.':4',
            'max_per_order' => DecimalCast::class.':4',
        ];
    }

    public function buyProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'buy_product_id');
    }

    public function buyBrand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'buy_brand_id');
    }

    public function getProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'get_product_id');
    }

    public function salesOrderLines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class, 'promotion_id');
    }
}
