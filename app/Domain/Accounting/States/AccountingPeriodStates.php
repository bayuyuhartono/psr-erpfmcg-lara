<?php

namespace App\Domain\Accounting\States;

use App\Support\Documents\DocumentStates;

/**
 * A period is open until someone closes it, and reopening is deliberate and
 * permissioned — it makes previously reported figures editable again.
 */
final class AccountingPeriodStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'open' => ['closed'],
            'closed' => ['open'],
        ];
    }

    public function frozen(): array
    {
        return ['closed'];
    }

    public function permissions(): array
    {
        return [
            'open>closed' => 'accounting.period.close',
            'closed>open' => 'accounting.period.close',
        ];
    }
}
