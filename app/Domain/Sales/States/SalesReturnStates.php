<?php

namespace App\Domain\Sales\States;

use App\Support\Documents\DocumentStates;

final class SalesReturnStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'draft' => ['posted', 'cancelled'],
            'posted' => ['cancelled'],
            'cancelled' => [],
        ];
    }

    public function frozen(): array
    {
        return ['posted', 'cancelled'];
    }
}
