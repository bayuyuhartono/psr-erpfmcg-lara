<?php

namespace App\Console\Commands;

use App\Domain\Accounting\Actions\CloseAccountingPeriod;
use App\Domain\Accounting\Exceptions\PeriodNotReady;
use App\Domain\Accounting\Services\InventoryValuation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class CloseMonth extends Command
{
    protected $signature = 'accounting:close {month : YYYY-MM} {--user= : Email of the person closing it} {--force : Close despite an unexplained stock difference} {--check : Report readiness without closing}';

    protected $description = 'Close an accounting period, refusing to if the books are not ready';

    public function handle(CloseAccountingPeriod $close, InventoryValuation $valuation): int
    {
        $month = CarbonImmutable::createFromFormat('Y-m-d', $this->argument('month').'-01');

        if ($this->option('check')) {
            $reconciliation = $valuation->reconcile($month->endOfMonth());
            $this->table(['Stock value', 'Inventory account', 'Difference', 'Reconciles'], [[
                $reconciliation['stock_value'],
                $reconciliation['account_balance'],
                $reconciliation['difference'],
                $reconciliation['reconciles'] ? 'yes' : 'no',
            ]]);

            return $reconciliation['reconciles'] ? self::SUCCESS : self::FAILURE;
        }

        $actor = User::query()->where('email', $this->option('user'))->first();

        if ($actor === null) {
            $this->error('Name who is closing the period with --user=email.');

            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            $close($month->year, $month->month, $actor, (bool) $this->option('force'));
        } catch (PeriodNotReady $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s closed in %.1fs.', $month->format('F Y'), microtime(true) - $started));

        return self::SUCCESS;
    }
}
