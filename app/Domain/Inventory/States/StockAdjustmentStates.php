<?php

namespace App\Domain\Inventory\States;

use App\Support\Documents\DocumentStates;

/**
 * Nothing about a count changes stock until an adjustment is approved (F-22).
 *
 * That approval is the point of the whole exercise: a variance is a question —
 * where did these go — and posting it without an answer turns a discrepancy
 * into a fact nobody investigated.
 */
final class StockAdjustmentStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'draft' => ['approved', 'cancelled'],
            'approved' => ['posted', 'cancelled'],
            'posted' => ['cancelled'],
            'cancelled' => [],
        ];
    }

    public function frozen(): array
    {
        return ['approved', 'posted', 'cancelled'];
    }

    public function permissions(): array
    {
        return ['draft>approved' => 'stock.adjust.approve'];
    }
}
