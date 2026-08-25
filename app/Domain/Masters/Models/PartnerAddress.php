<?php

namespace App\Domain\Masters\Models;

use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PartnerAddress extends DomainModel
{
    protected function casts(): array
    {
        return [
            'latitude' => DecimalCast::class.':7',
            'longitude' => DecimalCast::class.':7',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'partner_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'shipping_address_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'shipping_address_id');
    }
}
