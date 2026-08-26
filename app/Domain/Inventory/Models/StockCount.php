<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Masters\Models\Category;
use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockCount extends DomainModel
{
    protected $table = 'stock_counts';

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
