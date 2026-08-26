<?php

namespace App\Domain\Sales\States;

use App\Support\Documents\DocumentStates;

/**
 * `exchanged` is the Indonesian distribution step a general accounting package
 * has no name for: the invoice has been handed to the outlet on their
 * scheduled exchange day and is now waiting to be paid on theirs (F-50).
 *
 * `posted` is not frozen because `paid_amount` rises as payments land.
 */
final class SalesInvoiceStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'draft' => ['posted', 'cancelled'],
            'posted' => ['exchanged', 'partially_paid', 'paid', 'cancelled'],
            'exchanged' => ['partially_paid', 'paid', 'cancelled'],
            'partially_paid' => ['paid', 'posted', 'cancelled'],
            // Not final. A bounced cheque means the payment that settled this
            // never happened, so the invoice owes again (F-51). An invoice
            // that could not be reopened would stay paid on the strength of
            // money that never arrived.
            'paid' => ['posted', 'partially_paid'],
            'cancelled' => [],
        ];
    }

    public function frozen(): array
    {
        return ['cancelled'];
    }

    public function permissions(): array
    {
        return ['posted>cancelled' => 'sales_invoice.cancel'];
    }
}
