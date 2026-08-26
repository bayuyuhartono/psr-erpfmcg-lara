<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\States\StockAdjustmentStates;
use App\Domain\Shared\Models\DomainModel;
use App\Models\User;
use App\Support\Documents\DocumentStates;
use App\Support\Documents\HasDocumentState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockAdjustment extends DomainModel
{
    use HasDocumentState;

    protected $table = 'stock_adjustments';

    public static function states(): DocumentStates
    {
        return new StockAdjustmentStates;
    }

    protected function casts(): array
    {
        return ['adjustment_date' => 'immutable_date'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function count(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'count_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function stockAdjustmentLines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'adjustment_id');
    }
}
