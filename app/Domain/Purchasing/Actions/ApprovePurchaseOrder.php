<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Exceptions\ApprovalLimitExceeded;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Documents\Exceptions\TransitionNotPermitted;

/**
 * Approves a purchase order, which is the point the money is committed.
 *
 * The approver's ceiling comes from `role_permissions.threshold` — the same
 * mechanism behind discount approval (F-70). A buyer who may approve up to
 * Rp 50,000,000 cannot approve Rp 50,000,001, and the message says so with
 * both numbers rather than a flat refusal.
 */
final class ApprovePurchaseOrder
{
    public function __invoke(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        if (! $actor->hasPermission('purchase_order.approve')) {
            throw new TransitionNotPermitted(sprintf(
                '%s may not approve purchase orders.',
                $actor->full_name,
            ));
        }

        if (! $actor->hasPermission('purchase_order.approve', $order->total)) {
            throw new ApprovalLimitExceeded(sprintf(
                'Purchase order %s totals %s, above what %s may approve. Send it to someone with a higher limit.',
                $order->document_number,
                $order->total,
                $actor->full_name,
            ));
        }

        return $order->transitionTo('approved', $actor, [
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
        ]);
    }
}
