<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\StockAdjustment;
use App\Models\User;

/** Records who accepted the variance, and when. */
final class ApproveStockAdjustment
{
    public function __invoke(StockAdjustment $adjustment, User $actor): StockAdjustment
    {
        return $adjustment->transitionTo('approved', $actor, [
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
        ]);
    }
}
