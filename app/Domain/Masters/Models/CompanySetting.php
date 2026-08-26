<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;

final class CompanySetting extends DomainModel
{
    protected $table = 'company_settings';

    protected function casts(): array
    {
        return [
            'default_vat_percent' => DecimalCast::class.':2',
        ];
    }
}
