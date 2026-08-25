<?php

namespace App\Domain\Sales\Models;

use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Masters\Models\Product;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesReturnLine extends DomainModel
{
    protected function casts(): array
    {
        return [
            'unit_price' => DecimalCast::class.':2',
            'qty_base' => DecimalCast::class.':4',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }
}
