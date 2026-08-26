<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PrincipalClaim extends DomainModel
{
    protected $table = 'principal_claims';

    protected function casts(): array
    {
        return [
            'total_amount' => DecimalCast::class.':2',
            'approved_amount' => DecimalCast::class.':2',
        ];
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'principal_id');
    }

    public function principalClaimLines(): HasMany
    {
        return $this->hasMany(PrincipalClaimLine::class, 'claim_id');
    }
}
