<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Masters\Models\Product;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockTransferLine extends DomainModel
{
    protected $table = 'stock_transfer_lines';

    protected function casts(): array
    {
        return [
            'qty_base' => DecimalCast::class.':4',
            'qty_received' => DecimalCast::class.':4',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'transfer_id');
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
