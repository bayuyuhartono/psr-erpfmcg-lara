<?php

namespace App\Domain\Finance\States;

use App\Support\Documents\DocumentStates;

/**
 * The invoice exchange (F-50) — the step general accounting software has no
 * name for. Invoices are assembled on the customer's exchange day, handed over
 * against a signature, and settled when the money arrives on their payment day.
 */
final class InvoiceExchangeStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'draft' => ['handed_over', 'cancelled'],
            'handed_over' => ['settled', 'cancelled'],
            'settled' => [],
            'cancelled' => [],
        ];
    }

    public function frozen(): array
    {
        return ['settled', 'cancelled'];
    }
}
