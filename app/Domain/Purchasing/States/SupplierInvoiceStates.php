<?php

namespace App\Domain\Purchasing\States;

use App\Support\Documents\DocumentStates;

/**
 * `posted` is deliberately not frozen: `paid_amount` rises as payments are
 * allocated against the invoice (M6), and freezing it would leave that the
 * only way in a bulk update — the one hole the document guards have.
 *
 * What actually must not happen after posting is the invoice being posted
 * again, and the state machine already makes that impossible.
 */
final class SupplierInvoiceStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'draft' => ['posted', 'cancelled'],
            'posted' => ['paid', 'cancelled'],
            'paid' => [],
            'cancelled' => [],
        ];
    }

    public function frozen(): array
    {
        return ['cancelled'];
    }

    public function permissions(): array
    {
        return ['posted>cancelled' => 'supplier_invoice.cancel'];
    }
}
