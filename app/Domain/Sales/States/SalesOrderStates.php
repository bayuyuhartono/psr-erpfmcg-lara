<?php

namespace App\Domain\Sales\States;

use App\Support\Documents\DocumentStates;

/**
 * `credit_hold` is a state, not a flag (F-30).
 *
 * An order held for credit has not been rejected — it is waiting for a
 * decision by someone allowed to make it. Modelling it as a status means the
 * release is a transition with a name against it, rather than a boolean
 * somebody quietly cleared.
 */
final class SalesOrderStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'draft' => ['credit_hold', 'pending_approval', 'approved', 'cancelled'],
            'credit_hold' => ['approved', 'cancelled'],
            'pending_approval' => ['approved', 'draft', 'cancelled'],
            'approved' => ['partially_shipped', 'closed', 'cancelled'],
            'partially_shipped' => ['closed', 'cancelled'],
            'closed' => [],
            'cancelled' => [],
        ];
    }

    public function frozen(): array
    {
        return ['approved', 'partially_shipped', 'closed', 'cancelled'];
    }

    public function permissions(): array
    {
        return [
            'credit_hold>approved' => 'sales_order.release_hold',
            'pending_approval>approved' => 'sales_order.approve',
        ];
    }
}
