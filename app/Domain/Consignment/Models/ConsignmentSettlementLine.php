<?php

namespace App\Domain\Consignment\Models;

use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Masters\Models\Product;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ConsignmentSettlementLine extends DomainModel
{
    protected function casts(): array
    {
        return [
            'unit_price' => DecimalCast::class.':2',
            'line_total' => DecimalCast::class.':2',
            'qty_placed' => DecimalCast::class.':4',
            'qty_sold' => DecimalCast::class.':4',
            'qty_returned' => DecimalCast::class.':4',
            'qty_remaining' => DecimalCast::class.':4',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ConsignmentSettlement::class, 'settlement_id');
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
