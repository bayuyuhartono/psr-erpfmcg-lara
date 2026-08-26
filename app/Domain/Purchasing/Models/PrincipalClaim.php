<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Purchasing\States\PrincipalClaimStates;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Documents\DocumentStates;
use App\Support\Documents\HasDocumentState;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PrincipalClaim extends DomainModel
{
    use HasDocumentState;

    protected $table = 'principal_claims';

    public static function states(): DocumentStates
    {
        return new PrincipalClaimStates;
    }

    protected function casts(): array
    {
        return [
            'claim_date' => 'immutable_date',
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
