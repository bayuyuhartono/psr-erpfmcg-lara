<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Masters\Models\Product;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockCountLine extends DomainModel
{
    protected function casts(): array
    {
        return [
            'system_qty' => DecimalCast::class.':4',
            'counted_qty' => DecimalCast::class.':4',
            'variance' => DecimalCast::class.':4',
        ];
    }

    public function count(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'count_id');
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
