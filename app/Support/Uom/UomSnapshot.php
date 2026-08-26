<?php

namespace App\Support\Uom;

use Brick\Math\BigDecimal;

/**
 * A quantity as it should be written onto a transaction line: the unit the
 * user chose, how many of them, and — copied, not referenced — the conversion
 * factor in force at that moment.
 *
 * Storing only `product_id` and reading the factor back from the master means
 * last year's invoices silently change quantity the day someone corrects a
 * carton size (schema §11). `toLineAttributes()` exists so filling those four
 * columns is easier than filling them by hand and getting it wrong.
 */
final readonly class UomSnapshot
{
    public function __construct(
        public string $uom,
        public BigDecimal $quantity,
        public BigDecimal $quantityInBase,
        public BigDecimal $quantityBase,
    ) {}

    /** @return array{uom: string, qty: BigDecimal, qty_in_base: BigDecimal, qty_base: BigDecimal} */
    public function toLineAttributes(): array
    {
        return [
            'uom' => $this->uom,
            'qty' => $this->quantity,
            'qty_in_base' => $this->quantityInBase,
            'qty_base' => $this->quantityBase,
        ];
    }
}
