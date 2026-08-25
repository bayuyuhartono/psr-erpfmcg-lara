<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;

final class DiscountRule extends DomainModel
{
    protected function casts(): array
    {
        return [
            'min_amount' => DecimalCast::class.':2',
            'amount' => DecimalCast::class.':2',
            'percent' => DecimalCast::class.':2',
            'min_qty' => DecimalCast::class.':4',
        ];
    }
}
