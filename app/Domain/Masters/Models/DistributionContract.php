<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DistributionContract extends DomainModel
{
    protected function casts(): array
    {
        return [
            'monthly_target' => DecimalCast::class.':2',
            'credit_limit' => DecimalCast::class.':2',
        ];
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'principal_id');
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }
}
