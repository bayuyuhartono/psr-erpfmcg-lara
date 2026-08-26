<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;

final class DiscountRule extends DomainModel
{
    protected $table = 'discount_rules';

    protected function casts(): array
    {
        return [
            'percent' => DecimalCast::class.':2',
            'amount' => DecimalCast::class.':2',
            'min_qty' => DecimalCast::class.':4',
            'min_amount' => DecimalCast::class.':2',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }
}
