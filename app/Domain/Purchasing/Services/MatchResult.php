<?php

namespace App\Domain\Purchasing\Services;

use Brick\Math\BigDecimal;

final readonly class MatchResult
{
    /** @param  list<MatchVariance>  $variances */
    public function __construct(
        public array $variances,
        public BigDecimal $expectedTotal,
        public BigDecimal $invoicedTotal,
        public BigDecimal $tolerance,
    ) {}

    public function difference(): BigDecimal
    {
        return $this->invoicedTotal->minus($this->expectedTotal);
    }

    public function isWithinTolerance(): bool
    {
        return $this->difference()->abs()->isLessThanOrEqualTo($this->tolerance);
    }

    public function describe(): string
    {
        return implode('; ', array_map(
            fn (MatchVariance $variance): string => sprintf(
                '%s expected %s, invoiced %s (%s)',
                $variance->sku,
                $variance->expected,
                $variance->invoiced,
                $variance->explanation,
            ),
            $this->variances,
        ));
    }
}
