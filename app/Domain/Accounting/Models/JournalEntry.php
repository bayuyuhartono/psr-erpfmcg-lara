<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class JournalEntry extends DomainModel
{
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id');
    }
}
