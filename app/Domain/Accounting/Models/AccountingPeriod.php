<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\Models\DomainModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AccountingPeriod extends DomainModel
{
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
