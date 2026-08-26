<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\StockBatch;
use Brick\Math\BigDecimal;

/** How much to take from one batch, and why that batch came first. */
final readonly class PickAllocation
{
    public function __construct(
        public ?StockBatch $batch,
        public BigDecimal $quantityBase,
        public ?string $overrideReason = null,
    ) {}

    public function isOverride(): bool
    {
        return $this->overrideReason !== null;
    }
}
