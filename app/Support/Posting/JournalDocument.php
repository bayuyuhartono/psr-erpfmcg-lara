<?php

namespace App\Support\Posting;

use DateTimeInterface;

/**
 * A journal entry waiting to be posted. `documentType` is both the source
 * document type recorded on the entry and the key used to look up accounts in
 * journal_mappings — the two share one vocabulary on purpose.
 */
final readonly class JournalDocument
{
    /** @param list<JournalPosting> $postings */
    public function __construct(
        public string $documentType,
        public string $referenceId,
        public DateTimeInterface $entryDate,
        public array $postings,
        public ?string $description = null,
        public ?string $actorId = null,
    ) {}

    /** @return list<string> */
    public function postingKeys(): array
    {
        return array_values(array_unique(array_map(
            static fn (JournalPosting $posting): string => $posting->postingKey,
            $this->postings,
        )));
    }
}
