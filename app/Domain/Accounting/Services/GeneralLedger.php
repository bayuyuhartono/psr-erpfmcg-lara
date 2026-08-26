<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalLine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Every movement on an account, with a running balance and the document behind
 * each one (F-61).
 *
 * Only posted entries appear. A draft journal is somebody's intention, and a
 * ledger that included them would not reconcile to anything.
 */
final class GeneralLedger
{
    /**
     * @return array{opening: string, movements: list<array<string, string>>, closing: string}
     */
    public function for(ChartOfAccount $account, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to)->startOfDay();
        $opening = $this->balanceBefore($account, $from);

        // Ordered in SQL rather than in PHP: a ledger is read in date order,
        // and the database is where that belongs.
        $lines = JournalLine::query()
            ->with(['journalEntry', 'partner'])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->getKey())
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '>=', $from->format('Y-m-d'))
            ->whereDate('journal_entries.entry_date', '<=', $to->format('Y-m-d'))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.document_number')
            ->orderBy('journal_lines.id')
            ->select('journal_lines.*')
            ->get();

        $running = $opening;
        $movements = [];

        foreach ($lines as $line) {
            $running = $running->plus($this->signed($account, $line));

            $movements[] = [
                'date' => CarbonImmutable::parse($line->journalEntry->entry_date)->format('Y-m-d'),
                'entry' => $line->journalEntry->document_number,
                'ref_type' => (string) $line->journalEntry->ref_type,
                'ref_id' => (string) $line->journalEntry->ref_id,
                'partner' => $line->partner?->name ?? '',
                'description' => (string) ($line->description ?? $line->journalEntry->description),
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
                'balance' => (string) $running->toScale(2),
            ];
        }

        return [
            'opening' => (string) $opening->toScale(2),
            'movements' => $movements,
            'closing' => (string) $running->toScale(2),
        ];
    }

    /** Balance at a date, in the direction the account normally carries. */
    public function balanceAt(ChartOfAccount $account, DateTimeInterface $on): BigDecimal
    {
        return $this->balanceBefore($account, CarbonImmutable::instance($on)->addDay());
    }

    private function balanceBefore(ChartOfAccount $account, CarbonImmutable $from): BigDecimal
    {
        $totals = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->getKey())
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '<', $from->format('Y-m-d'))
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS debits, COALESCE(SUM(journal_lines.credit), 0) AS credits')
            ->first();

        $movement = BigDecimal::of($totals->debits)->minus($totals->credits);

        return $this->isDebitAccount($account) ? $movement : $movement->negated();
    }

    private function signed(ChartOfAccount $account, JournalLine $line): BigDecimal
    {
        $movement = $line->debit->minus($line->credit);

        return $this->isDebitAccount($account) ? $movement : $movement->negated();
    }

    /**
     * Assets and expenses grow on the debit side; liabilities, equity and
     * revenue grow on the credit side. Reporting both as raw debit-minus-credit
     * would show every payable as a negative number.
     */
    private function isDebitAccount(ChartOfAccount $account): bool
    {
        return in_array($account->account_type, ['asset', 'expense'], true);
    }
}
