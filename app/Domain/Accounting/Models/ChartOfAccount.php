<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Finance\Models\Payment;
use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ChartOfAccount extends DomainModel
{
    protected $table = 'chart_of_accounts';

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'cash_account_id');
    }

    public function chartOfAccounts(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_id');
    }

    public function journalMappings(): HasMany
    {
        return $this->hasMany(JournalMapping::class, 'account_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }
}
