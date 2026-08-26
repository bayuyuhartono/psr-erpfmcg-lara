<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\States\JournalEntryStates;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Documents\DocumentStates;
use App\Support\Documents\HasDocumentState;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class JournalEntry extends DomainModel
{
    use HasDocumentState;

    protected $table = 'journal_entries';

    public static function states(): DocumentStates
    {
        return new JournalEntryStates;
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id');
    }
}
