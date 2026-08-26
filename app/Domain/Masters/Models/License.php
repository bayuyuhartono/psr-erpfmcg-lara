<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;

final class License extends DomainModel
{
    protected $table = 'licenses';

    protected function casts(): array
    {
        return [
            // Not an expiry. The software is sold, not rented — this is when
            // support and updates end, and nothing stops working (9.6).
            'supported_until' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'enabled_modules' => 'array',
        ];
    }
}
