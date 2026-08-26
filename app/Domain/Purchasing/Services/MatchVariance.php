<?php

namespace App\Domain\Purchasing\Services;

use Brick\Math\BigDecimal;

/** One line where the invoice disagrees with the order or the receipt. */
final readonly class MatchVariance
{
    public function __construct(
        public string $sku,
        public BigDecimal $expected,
        public BigDecimal $invoiced,
        public string $explanation,
    ) {}

    public function difference(): BigDecimal
    {
        return $this->invoiced->minus($this->expected);
    }
}
