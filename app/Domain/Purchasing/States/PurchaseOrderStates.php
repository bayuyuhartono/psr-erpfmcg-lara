<?php

namespace App\Domain\Purchasing\States;

use App\Support\Documents\DocumentStates;

/**
 * A purchase order's life.
 *
 * `approved` is the gate that matters: nothing may be received against an
 * order that has not been approved, so the approval is where the money is
 * actually committed. Receiving moves it on from there without anyone
 * touching the status by hand.
 */
final class PurchaseOrderStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'draft' => ['pending_approval', 'cancelled'],
            'pending_approval' => ['approved', 'draft', 'cancelled'],
            'approved' => ['partially_received', 'closed', 'cancelled'],
            'partially_received' => ['closed', 'cancelled'],
            'closed' => [],
            'cancelled' => [],
        ];
    }

    /**
     * Only the draft is editable. Once it has been sent for approval the
     * figures someone is approving have to stop moving underneath them.
     */
    public function frozen(): array
    {
        return ['pending_approval', 'approved', 'partially_received', 'closed', 'cancelled'];
    }

    public function permissions(): array
    {
        return [
            'pending_approval>approved' => 'purchase_order.approve',
            'approved>cancelled' => 'purchase_order.approve',
            'partially_received>cancelled' => 'purchase_order.approve',
        ];
    }
}
