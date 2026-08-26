<?php

namespace App\Domain\Inventory\Services;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * One line of a stock card, carrying the reference to the document that caused
 * it. That reference is the drill-through (F-21): every movement can be traced
 * back to the receipt, delivery or count that made it.
 */
final readonly class StockCardEntry
{
    public function __construct(
        public int $ledgerId,
        public CarbonImmutable $date,
        public string $referenceType,
        public string $referenceId,
        public ?string $documentNumber,
        public ?string $batchNumber,
        public BigDecimal $quantity,
        public BigDecimal $unitCost,
        public BigDecimal $value,
        public BigDecimal $runningQuantity,
        public BigDecimal $runningValue,
        public bool $isReversal,
    ) {}

    public function movement(): string
    {
        return $this->quantity->isNegative() ? 'out' : 'in';
    }
}
