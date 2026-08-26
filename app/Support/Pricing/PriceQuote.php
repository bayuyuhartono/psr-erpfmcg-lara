<?php

namespace App\Support\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What a line costs, and why — ready to be copied onto a transaction line.
 *
 * The percentages are kept separately rather than collapsed into one number
 * because they compound, and because a customer asking why an invoice says
 * what it says needs to see 5% then 3%, not 7.85%.
 */
final readonly class PriceQuote
{
    /** @param  list<BigDecimal>  $discountPercents  in the order they compound */
    public function __construct(
        public BigDecimal $unitPrice,
        public array $discountPercents,
        public BigDecimal $discountAmount,
        public BigDecimal $quantity,
        public BigDecimal $lineTotal,
        public ?string $priceListId,
    ) {}

    public function grossTotal(): BigDecimal
    {
        return $this->quantity->multipliedBy($this->unitPrice)->toScale(2, RoundingMode::HALF_UP);
    }

    /**
     * The single number a discount approval threshold is compared against
     * (F-32). Derived, never stored: the compounded percentages are the truth.
     */
    public function effectiveDiscountPercent(): BigDecimal
    {
        $gross = $this->grossTotal();

        if ($gross->isZero()) {
            return BigDecimal::zero()->toScale(2);
        }

        return $gross->minus($this->lineTotal)
            ->multipliedBy(100)
            ->dividedBy($gross, 2, RoundingMode::HALF_UP);
    }

    /** @return array<string, BigDecimal> */
    public function toLineAttributes(): array
    {
        return [
            'unit_price' => $this->unitPrice,
            'discount1_percent' => $this->discountPercents[0] ?? BigDecimal::zero()->toScale(2),
            'discount2_percent' => $this->discountPercents[1] ?? BigDecimal::zero()->toScale(2),
            'discount3_percent' => $this->discountPercents[2] ?? BigDecimal::zero()->toScale(2),
            'discount_amount' => $this->discountAmount,
            'line_total' => $this->lineTotal,
        ];
    }
}
