<?php

namespace App\Domain\Masters\Models;

use App\Domain\Inventory\Models\StockCount;
use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Category extends DomainModel
{
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function stockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class, 'scope_category_id');
    }
}
