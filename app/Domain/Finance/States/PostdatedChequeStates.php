<?php

namespace App\Domain\Finance\States;

use App\Support\Documents\DocumentStates;

/**
 * A postdated cheque is a promise with a date on it (F-51).
 *
 * `held → deposited → cleared` is the hopeful path; `bounced` is why the whole
 * thing is modelled separately from cash. A distributor who counts uncleared
 * cheques as money has a cash position that is wrong by however many of them
 * are about to come back.
 */
final class PostdatedChequeStates extends DocumentStates
{
    public function transitions(): array
    {
        return [
            'held' => ['deposited', 'cleared', 'bounced'],
            'deposited' => ['cleared', 'bounced'],
            // Cleared is final. A cheque that clears and is later reversed by
            // the bank is a new event, not an edit to this one.
            'cleared' => [],
            'bounced' => [],
        ];
    }

    public function frozen(): array
    {
        return ['cleared', 'bounced'];
    }
}
