<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\States\StockTransferStates;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Documents\DocumentStates;
use App\Support\Documents\HasDocumentState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockTransfer extends DomainModel
{
    use HasDocumentState;

    protected $table = 'stock_transfers';

    public static function states(): DocumentStates
    {
        return new StockTransferStates;
    }

    protected function casts(): array
    {
        return ['transfer_date' => 'immutable_date'];
    }

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
