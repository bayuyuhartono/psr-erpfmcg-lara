<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CustomerTier extends DomainModel
{
    protected $table = 'customer_tiers';

    /**
     * Added by hand: `customer_tiers.price_list_id` is one of the deferred
     * foreign keys, so the model generator never saw it.
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function businessPartners(): HasMany
    {
        return $this->hasMany(BusinessPartner::class, 'tier_id');
    }
}
