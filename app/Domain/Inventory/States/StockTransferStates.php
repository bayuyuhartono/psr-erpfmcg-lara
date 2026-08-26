<?php

namespace App\Domain\Inventory\States;

use App\Support\Documents\DocumentStates;

/**
 * Goods in transit are still ours and still on the books — they are simply in
 * neither warehouse's balance. `shipped` is that window, and it closes when
 * the receiving end confirms what turned up.
 */
final class StockTransferStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'draft' => ['shipped', 'cancelled'],
            'shipped' => ['received', 'cancelled'],
            'received' => [],
            'cancelled' => [],
        ];
    }

    public function frozen(): array
    {
        return ['shipped', 'received', 'cancelled'];
    }

    public function permissions(): array
    {
        return ['draft>shipped' => 'stock.transfer.post'];
    }
}
