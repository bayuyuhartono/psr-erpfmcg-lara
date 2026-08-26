<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Exceptions\PeriodNotReady;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\InventoryValuation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Closes a month (F-60).
 *
 * Two checks before it will. A draft journal in the period is somebody's
 * unfinished work, and closing over it means the figures reported are not the
 * figures that will end up there. Stock that does not reconcile to the
 * inventory account means something posted without its other half — closing on
 * top of that buries it in a month nobody will reopen.
 *
 * Neither check is expensive: both are aggregates, and the close is a single
 * status change. The NFR asks for under two minutes; this is a query and a
 * transition.
 */
final class CloseAccountingPeriod
{
    public function __construct(private readonly InventoryValuation $valuation) {}

    public function __invoke(int $year, int $month, User $actor, bool $force = false): AccountingPeriod
    {
        $period = AccountingPeriod::firstOrCreate(['year' => $year, 'month' => $month]);
        $ends = CarbonImmutable::create($year, $month, 1)->endOfMonth();

        $drafts = JournalEntry::query()
            ->where('status', 'draft')
            ->whereYear('entry_date', $year)
            ->whereMonth('entry_date', $month)
            ->count();

        if ($drafts > 0) {
            throw new PeriodNotReady(sprintf(
                '%s has %d unposted journal(s). Post or cancel them first — closing over a draft reports figures that are not final.',
                $ends->format('F Y'),
                $drafts,
            ));
        }

        $reconciliation = $this->valuation->reconcile($ends);

        if (! $reconciliation['reconciles'] && ! $force) {
            throw new PeriodNotReady(sprintf(
                'Stock is worth %s but the inventory account holds %s, a difference of %s. Something posted stock without a journal, or the other way round. Run stock:rebuild-balances, or close with --force if the difference is understood and accepted.',
                $reconciliation['stock_value'],
                $reconciliation['account_balance'],
                $reconciliation['difference'],
            ));
        }

        return DB::transaction(fn (): AccountingPeriod => $period->transitionTo('closed', $actor, [
            'closed_by' => $actor->getKey(),
            'closed_at' => now(),
        ]));
    }
}
