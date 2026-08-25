<?php

namespace App\Domain\Masters\Models;

use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Shared\Models\DomainModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Salesperson extends DomainModel
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function businessPartners(): HasMany
    {
        return $this->hasMany(BusinessPartner::class, 'salesperson_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'salesperson_id');
    }
}
