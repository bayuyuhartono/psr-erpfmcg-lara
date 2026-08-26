<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\States\StockCountStates;
use App\Domain\Masters\Models\Category;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Documents\DocumentStates;
use App\Support\Documents\HasDocumentState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockCount extends DomainModel
{
    use HasDocumentState;

    protected $table = 'stock_counts';

    public static function states(): DocumentStates
    {
        return new StockCountStates;
    }

    protected function casts(): array
    {
        return ['count_date' => 'immutable_date'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function scopeCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'scope_category_id');
    }

    public function stockCountLines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'count_id');
    }

    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class, 'count_id');
    }
}
