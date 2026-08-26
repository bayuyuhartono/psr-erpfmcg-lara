<?php

namespace App\Domain\Purchasing\States;

use App\Support\Documents\DocumentStates;

/** Goods going back to the supplier: drafted, posted once, never edited. */
final class PurchaseReturnStates extends DocumentStates
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
        return ['posted>cancelled' => 'goods_receipt.cancel'];
    }
}
