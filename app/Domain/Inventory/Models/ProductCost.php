<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Masters\Models\Product;
use App\Domain\Shared\Models\PivotModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductCost extends PivotModel
{
    protected $table = 'product_costs';

    protected function casts(): array
    {
        return [
            'total_value' => DecimalCast::class.':2',
            'total_qty' => DecimalCast::class.':4',
            'average_cost' => DecimalCast::class.':4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
