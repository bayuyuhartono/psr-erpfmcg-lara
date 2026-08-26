<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Exceptions\InvalidPurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Models\User;

/** Sends a draft for approval, freezing the figures being approved. */
final class SubmitPurchaseOrder
{
    public function __invoke(PurchaseOrder $order, ?User $actor = null): PurchaseOrder
    {
        if ($order->purchaseOrderLines()->doesntExist()) {
            throw new InvalidPurchaseOrder(sprintf('Purchase order %s has no lines to approve.', $order->document_number));
        }

        return $order->transitionTo('pending_approval', $actor);
    }
}
