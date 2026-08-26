<?php

namespace App\Domain\Sales\States;

use App\Support\Documents\DocumentStates;

/**
 * `delivered` is not the end of the question — `failed` exists because an
 * outlet that is shut, or refuses the load, is a normal Tuesday and the goods
 * come back on the truck either way.
 */
final class DeliveryStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'draft' => ['picking', 'cancelled'],
            'picking' => ['loaded', 'draft', 'cancelled'],
            'loaded' => ['in_transit', 'cancelled'],
            'in_transit' => ['delivered', 'failed'],
            'delivered' => [],
            'failed' => [],
            'cancelled' => [],
        ];
    }

    /** Stock leaves when the load does, so nothing about it changes after. */
    public function frozen(): array
    {
        return ['in_transit', 'delivered', 'failed', 'cancelled'];
    }
}
