<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class JournalMapping extends DomainModel
{
    protected $table = 'journal_mappings';

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
