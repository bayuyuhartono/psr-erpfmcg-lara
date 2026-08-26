<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Masters\Models\Product;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockReservation extends DomainModel
{
    protected $table = 'stock_reservations';

    protected function casts(): array
    {
        return [
            'qty_base' => DecimalCast::class.':4',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
