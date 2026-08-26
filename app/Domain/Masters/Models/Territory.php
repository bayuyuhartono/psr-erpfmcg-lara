<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Territory extends DomainModel
{
    protected $table = 'territories';

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'parent_id');
    }

    public function territories(): HasMany
    {
        return $this->hasMany(Territory::class, 'parent_id');
    }

    public function salespeople(): HasMany
    {
        return $this->hasMany(Salesperson::class, 'territory_id');
    }

    public function businessPartners(): HasMany
    {
        return $this->hasMany(BusinessPartner::class, 'territory_id');
    }

    public function distributionContracts(): HasMany
    {
        return $this->hasMany(DistributionContract::class, 'territory_id');
    }
}
