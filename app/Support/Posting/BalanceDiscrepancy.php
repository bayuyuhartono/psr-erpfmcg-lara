<?php

namespace App\Support\Posting;

use Brick\Math\BigDecimal;

/**
 * One place where the cache and the ledger disagree. A null on either side
 * means the row exists only on the other: no ledger rows behind a cached
 * balance, or no cached balance for stock the ledger says is there.
 */
final readonly class BalanceDiscrepancy
{
    public function __construct(
        public string $warehouseId,
        public string $productId,
        public ?string $batchId,
        public ?string $ownerId,
        public ?BigDecimal $cachedQuantity,
        public ?BigDecimal $ledgerQuantity,
        public ?BigDecimal $cachedValue,
        public ?BigDecimal $ledgerValue,
    ) {}

    public function kind(): string
    {
        return match (true) {
            $this->cachedQuantity === null => 'missing from cache',
            $this->ledgerQuantity === null => 'no ledger rows',
            default => 'disagrees',
        };
    }
}
