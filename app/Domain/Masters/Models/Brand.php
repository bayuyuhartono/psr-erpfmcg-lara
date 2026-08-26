<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Brand extends DomainModel
{
    protected $table = 'brands';

    public function principal(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'principal_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'brand_id');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class, 'buy_brand_id');
    }

    public function rebatePrograms(): HasMany
    {
        return $this->hasMany(RebateProgram::class, 'brand_id');
    }
}
