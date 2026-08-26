<?php

namespace App\Domain\Inventory\States;

use App\Support\Documents\DocumentStates;

/**
 * A count runs while the warehouse keeps working (F-22).
 *
 * `counting` is the state that makes that possible: the system quantity was
 * frozen when the count opened, so goods moving in and out afterwards change
 * the balance without changing what the count is being measured against.
 */
final class StockCountStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'draft' => ['counting', 'cancelled'],
            'counting' => ['reviewed', 'cancelled'],
            'reviewed' => ['posted', 'counting', 'cancelled'],
            'posted' => [],
            'cancelled' => [],
        ];
    }

    public function frozen(): array
    {
        return ['posted', 'cancelled'];
    }
}
