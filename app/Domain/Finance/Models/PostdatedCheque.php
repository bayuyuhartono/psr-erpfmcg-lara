<?php

namespace App\Domain\Finance\Models;

use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PostdatedCheque extends DomainModel
{
    protected function casts(): array
    {
        return [
            'amount' => DecimalCast::class.':2',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'partner_id');
    }
}
