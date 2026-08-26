<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\ChartOfAccount;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Profit and loss, and the balance sheet (F-61).
 *
 * Every figure is a list of accounts and every account drills to its ledger,
 * which drills to the documents behind it. An owner who cannot get from "cost
 * of goods looks high" to the goods receipts that made it will stop trusting
 * the number and go back to asking staff.
 */
final class FinancialStatements
{
    public function __construct(private readonly GeneralLedger $ledger) {}

    /**
     * @return array{revenue: list<array<string, string>>, expenses: list<array<string, string>>,
     *     total_revenue: string, total_expenses: string, profit: string}
     */
    public function profitAndLoss(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $revenue = $this->movements('revenue', $from, $to);
        $expenses = $this->movements('expense', $from, $to);
        $totalRevenue = $this->sum($revenue);
        $totalExpenses = $this->sum($expenses);

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'total_revenue' => (string) $totalRevenue->toScale(2),
            'total_expenses' => (string) $totalExpenses->toScale(2),
            'profit' => (string) $totalRevenue->minus($totalExpenses)->toScale(2),
        ];
    }

    /**
     * @return array{assets: list<array<string, string>>, liabilities: list<array<string, string>>,
     *     equity: list<array<string, string>>, total_assets: string, total_liabilities_and_equity: string,
     *     retained_this_year: string, balances: bool}
     */
    public function balanceSheet(DateTimeInterface $on, ?DateTimeInterface $yearStart = null): array
    {
        $on = CarbonImmutable::instance($on);
        $yearStart ??= $on->startOfYear();

        $assets = $this->balances('asset', $on);
        $liabilities = $this->balances('liability', $on);
        $equity = $this->balances('equity', $on);

        // This year's profit is not in a nominal account until the year is
        // closed, so the balance sheet has to carry it or it will not balance.
        $retained = BigDecimal::of($this->profitAndLoss($yearStart, $on)['profit']);

        $totalAssets = $this->sum($assets);
        $totalOther = $this->sum($liabilities)->plus($this->sum($equity))->plus($retained);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'retained_this_year' => (string) $retained->toScale(2),
            'total_assets' => (string) $totalAssets->toScale(2),
            'total_liabilities_and_equity' => (string) $totalOther->toScale(2),
            'balances' => $totalAssets->toScale(2)->isEqualTo($totalOther->toScale(2)),
        ];
    }

    /** @return list<array<string, string>> */
    private function movements(string $type, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return $this->accounts($type)
            ->map(function (ChartOfAccount $account) use ($from, $to): array {
                $ledger = $this->ledger->for($account, $from, $to);

                return [
                    'code' => $account->code,
                    'name' => $account->name,
                    'amount' => (string) BigDecimal::of($ledger['closing'])->minus($ledger['opening'])->toScale(2),
                ];
            })
            ->reject(fn (array $row): bool => BigDecimal::of($row['amount'])->isZero())
            ->values()
            ->all();
    }

    /** @return list<array<string, string>> */
    private function balances(string $type, DateTimeInterface $on): array
    {
        return $this->accounts($type)
            ->map(fn (ChartOfAccount $account): array => [
                'code' => $account->code,
                'name' => $account->name,
                'amount' => (string) $this->ledger->balanceAt($account, $on)->toScale(2),
            ])
            ->reject(fn (array $row): bool => BigDecimal::of($row['amount'])->isZero())
            ->values()
            ->all();
    }

    private function accounts(string $type)
    {
        return ChartOfAccount::query()->where('account_type', $type)->orderBy('code')->get();
    }

    /** @param  list<array<string, string>>  $rows */
    private function sum(array $rows): BigDecimal
    {
        return array_reduce(
            $rows,
            fn (BigDecimal $carry, array $row): BigDecimal => $carry->plus($row['amount']),
            BigDecimal::zero(),
        );
    }
}
