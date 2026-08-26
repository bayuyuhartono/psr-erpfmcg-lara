<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\States\AccountingPeriodStates;
use App\Domain\Shared\Models\DomainModel;
use App\Models\User;
use App\Support\Documents\DocumentStates;
use App\Support\Documents\HasDocumentState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AccountingPeriod extends DomainModel
{
    use HasDocumentState;

    protected $table = 'accounting_periods';

    public static function states(): DocumentStates
    {
        return new AccountingPeriodStates;
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
