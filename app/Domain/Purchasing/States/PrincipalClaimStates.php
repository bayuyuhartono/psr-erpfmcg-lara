<?php

namespace App\Domain\Purchasing\States;

use App\Support\Documents\DocumentStates;

/**
 * A claim against a principal for expired, damaged or recalled goods.
 *
 * The statuses after `submitted` record what the principal decided, not what
 * we decided — which is why a rejected claim can go back to draft to be
 * revised and sent again, and why `approved_amount` may be less than what was
 * claimed.
 */
final class PrincipalClaimStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'draft' => ['submitted'],
            'submitted' => ['approved', 'rejected'],
            'approved' => ['settled'],
            'rejected' => ['draft'],
            'settled' => [],
        ];
    }

    /** Once money has changed hands the claim stops moving. */
    public function frozen(): array
    {
        return ['settled'];
    }
}
