<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockTransfer extends DomainModel
{
    protected $table = 'stock_transfers';

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function stockTransferLines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'transfer_id');
    }
}
