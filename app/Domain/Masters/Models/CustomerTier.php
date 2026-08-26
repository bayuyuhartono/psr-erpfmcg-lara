<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CustomerTier extends DomainModel
{
    protected $table = 'customer_tiers';

    public function businessPartners(): HasMany
    {
        return $this->hasMany(BusinessPartner::class, 'tier_id');
    }
}
