<?php

namespace App\Support\Posting;

use Brick\Math\BigDecimal;
use DateTimeInterface;

final readonly class StockMovement
{
    public BigDecimal $quantity;

    public ?BigDecimal $unitCost;

    public function __construct(
        public string $warehouseId,
        public string $productId,
        BigDecimal|string|int $quantity,
        BigDecimal|string|int|null $unitCost,
        public string $referenceType,
        public string $referenceId,
        public DateTimeInterface $transactionDate,
        public ?string $referenceLineId = null,
        public ?string $locationId = null,
        public ?string $batchId = null,
        public ?string $ownerId = null,
        public ?string $actorId = null,
        // Journal attribution only: the partner lands on the counterpart line
        // (the supplier we owe, the customer we sold to), the branch on both.
        public ?string $partnerId = null,
        public ?string $branchId = null,
        // Narration for the journal entry this movement posts.
        public ?string $description = null,
        // Set only by StockReversal: the ledger row this movement cancels.
        // Its presence also pins the unit cost — a reversal backs the original
        // value out exactly, it does not re-price at today's average.
        public ?int $reversesLedgerId = null,
    ) {
        $this->quantity = BigDecimal::of($quantity);
        $this->unitCost = $unitCost === null ? null : BigDecimal::of($unitCost);
    }
}
