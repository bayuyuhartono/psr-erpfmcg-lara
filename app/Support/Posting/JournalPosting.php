<?php

namespace App\Support\Posting;

use Brick\Math\BigDecimal;

/**
 * One side of a journal entry, named by its posting key rather than by an
 * account code. The key is resolved against journal_mappings at post time, so
 * no account code ever appears in posting logic (schema §11, F-60).
 */
final readonly class JournalPosting
{
    public BigDecimal $amount;

    private function __construct(
        public string $postingKey,
        public bool $isDebit,
        BigDecimal|string|int $amount,
        public ?string $partnerId,
        public ?string $branchId,
        public ?string $description,
    ) {
        $this->amount = BigDecimal::of($amount);
    }

    public static function debit(
        string $postingKey,
        BigDecimal|string|int $amount,
        ?string $partnerId = null,
        ?string $branchId = null,
        ?string $description = null,
    ): self {
        return new self($postingKey, true, $amount, $partnerId, $branchId, $description);
    }

    public static function credit(
        string $postingKey,
        BigDecimal|string|int $amount,
        ?string $partnerId = null,
        ?string $branchId = null,
        ?string $description = null,
    ): self {
        return new self($postingKey, false, $amount, $partnerId, $branchId, $description);
    }
}
