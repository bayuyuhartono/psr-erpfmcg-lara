<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Sales\Models\DeliveryLine;
use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseLocation extends DomainModel
{
    protected $table = 'warehouse_locations';

    /**
     * Picking list order (F-40). Staff walk the racks in `pick_sequence`
     * order, so an unsequenced location sorts last rather than sending someone
     * back down an aisle they have already walked.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInPickOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('pick_sequence IS NULL')
            ->orderBy('pick_sequence')
            ->orderBy('code');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function stockLedger(): HasMany
    {
        return $this->hasMany(StockLedger::class, 'location_id');
    }

    public function goodsReceiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'location_id');
    }

    public function deliveryLines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class, 'location_id');
    }
}
