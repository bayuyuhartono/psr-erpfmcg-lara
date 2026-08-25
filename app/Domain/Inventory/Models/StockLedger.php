<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Scopes\OwnedStock;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Product;
use App\Domain\Shared\Models\IncrementingModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockLedger extends IncrementingModel
{
    public $incrementing = true;

    protected $keyType = 'int';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'value' => DecimalCast::class.':2',
            'qty_base' => DecimalCast::class.':4',
            'unit_cost' => DecimalCast::class.':4',
        ];
    }

    protected static function booted(): void
    {
        self::addGlobalScope(new OwnedStock);
    }

    public function scopeIncludingConsigned($query)
    {
        return $query->withoutGlobalScope(OwnedStock::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'owner_id');
    }
}
