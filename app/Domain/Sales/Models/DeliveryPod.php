<?php

namespace App\Domain\Sales\Models;

use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeliveryPod extends DomainModel
{
    protected $table = 'delivery_pods';

    protected function casts(): array
    {
        return [
            'latitude' => DecimalCast::class.':7',
            'longitude' => DecimalCast::class.':7',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'delivery_id');
    }
}
