<?php

namespace App\Domain\Accounting\States;

use App\Support\Documents\DocumentStates;

/**
 * Journals posted automatically by JournalPoster are born `posted` — the
 * document that triggered them was already authorised, and a draft journal
 * nobody posts is a hole in the ledger. Only a manual journal starts in draft,
 * and only that path is permission-gated.
 *
 * A posted entry is never edited or deleted: it is reversed by a counter-entry.
 */
final class JournalEntryStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'draft' => ['posted'],
            'posted' => ['reversed'],
            'reversed' => [],
        ];
    }

    public function frozen(): array
    {
        return ['posted', 'reversed'];
    }

    public function permissions(): array
    {
        return ['draft>posted' => 'journal.post'];
    }
}
