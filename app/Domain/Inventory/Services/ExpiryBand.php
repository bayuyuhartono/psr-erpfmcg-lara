<?php

namespace App\Domain\Inventory\Services;

use Brick\Math\BigDecimal;

/** Stock grouped by how much life it has left, and what that is worth. */
final readonly class ExpiryBand
{
    /** @param  list<array{sku: string, batch: string, expiry: string, qty: string, value: string}>  $items */
    public function __construct(
        public string $label,
        public ?int $withinDays,
        public BigDecimal $quantity,
        public BigDecimal $value,
        public array $items,
    ) {}
}
