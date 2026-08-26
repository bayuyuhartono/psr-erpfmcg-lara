<?php

namespace App\Support\Posting;

use App\Domain\Accounting\Models\JournalEntry;

final readonly class StockReversalResult
{
    /**
     * @param  list<StockPostingResult>  $movements  the counter-postings written
     * @param  list<JournalEntry>  $reversedEntries  the original journals, now marked reversed
     */
    public function __construct(
        public array $movements,
        public array $reversedEntries,
    ) {}
}
