<?php

namespace App\Domain\Purchasing\States;

use App\Support\Documents\DocumentStates;

/**
 * A goods receipt is drafted while the goods are being checked, then posted
 * once — and never edited afterwards (F-10). A mistake found later is
 * corrected by cancelling, which reverses the stock with a counter-journal
 * rather than rewriting what was recorded.
 */
final class GoodsReceiptStates extends DocumentStates
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
