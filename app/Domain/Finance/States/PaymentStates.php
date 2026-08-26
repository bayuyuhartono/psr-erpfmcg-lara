<?php

namespace App\Domain\Finance\States;

use App\Support\Documents\DocumentStates;

final class PaymentStates extends DocumentStates
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

    public function permissions(): array
    {
        return ['posted>cancelled' => 'payment.approve'];
    }
}
