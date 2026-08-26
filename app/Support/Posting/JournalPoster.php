<?php

namespace App\Support\Posting;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalMapping;
use App\Models\User;
use App\Support\Posting\Exceptions\UnbalancedJournal;
use App\Support\Posting\Exceptions\UnmappedAccount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only class that writes journal_entries and journal_lines.
 *
 * Accounts are resolved from journal_mappings for the document type, never
 * hardcoded: every client has a different chart of accounts, and a hardcoded
 * code is a per-client fork (schema §11, F-60).
 */
final class JournalPoster
{
    private const MONEY_SCALE = 2;

    public function __construct(
        private readonly DocumentNumberer $numberer,
        private readonly PeriodGuard $periods,
    ) {}

    public function post(JournalDocument $document): JournalEntry
    {
        // Checked here as well as in StockPosting: a manual journal (7.3) never
        // touches stock, and a stock movement can post without a journal.
        // Neither path may reach into a closed month.
        $this->periods->assertOpen($document->entryDate, sprintf('This %s journal', $document->documentType));
        $this->assertBalanced($document);
        $accounts = $this->accountsFor($document);

        return DB::transaction(function () use ($document, $accounts): JournalEntry {
            $entry = JournalEntry::create([
                'document_number' => $this->numberer->next('JE', CarbonImmutable::instance($document->entryDate)),
                'entry_date' => $document->entryDate->format('Y-m-d'),
                'description' => $document->description,
                'ref_type' => $document->documentType,
                'ref_id' => $document->referenceId,
                'status' => 'posted',
                'created_by' => $document->actorId,
            ]);

            foreach ($document->postings as $posting) {
                $amount = $this->money($posting->amount);
                $entry->journalLines()->create([
                    'account_id' => $accounts[$posting->postingKey],
                    'partner_id' => $posting->partnerId,
                    'branch_id' => $posting->branchId,
                    'debit' => $posting->isDebit ? $amount : BigDecimal::zero(),
                    'credit' => $posting->isDebit ? BigDecimal::zero() : $amount,
                    'description' => $posting->description,
                    'created_by' => $document->actorId,
                ]);
            }

            return $entry->load('journalLines');
        });
    }

    /**
     * A manual journal, naming accounts directly (F-60).
     *
     * Everything automatic goes through `post()` and resolves accounts from
     * `journal_mappings`, because a document type should not know account
     * codes. A manual adjustment is the opposite case: a person has decided
     * which accounts, and there is no document type to map from.
     *
     * It still comes through here rather than writing `journal_entries`
     * itself, so the balance check and the period lock apply to it too.
     *
     * @param  list<array{account_id: string, debit?: string, credit?: string, partner_id?: string, branch_id?: string, description?: string}>  $lines
     */
    public function postManual(
        string $description,
        DateTimeInterface $entryDate,
        array $lines,
        ?string $actorId = null,
        string $status = 'posted',
    ): JournalEntry {
        $this->periods->assertOpen($entryDate, 'This manual journal');

        $debits = BigDecimal::zero();
        $credits = BigDecimal::zero();

        foreach ($lines as $line) {
            $debits = $debits->plus($this->money(BigDecimal::of($line['debit'] ?? 0)));
            $credits = $credits->plus($this->money(BigDecimal::of($line['credit'] ?? 0)));
        }

        if ($lines === [] || (! $debits->isEqualTo($credits))) {
            throw new UnbalancedJournal(sprintf(
                'Manual journal does not balance: debits %s, credits %s.',
                $debits,
                $credits,
            ));
        }

        if ($debits->isZero()) {
            throw new UnbalancedJournal('A manual journal of nothing is not an adjustment.');
        }

        return DB::transaction(function () use ($description, $entryDate, $lines, $actorId, $status): JournalEntry {
            $entry = JournalEntry::create([
                'document_number' => $this->numberer->next('JE', CarbonImmutable::instance($entryDate)),
                'entry_date' => $entryDate->format('Y-m-d'),
                'description' => $description,
                'ref_type' => 'manual',
                'ref_id' => null,
                'status' => 'draft',
                'created_by' => $actorId,
            ]);

            foreach ($lines as $line) {
                $entry->journalLines()->create([
                    'account_id' => $line['account_id'],
                    'partner_id' => $line['partner_id'] ?? null,
                    'branch_id' => $line['branch_id'] ?? null,
                    'debit' => $this->money(BigDecimal::of($line['debit'] ?? 0)),
                    'credit' => $this->money(BigDecimal::of($line['credit'] ?? 0)),
                    'description' => $line['description'] ?? null,
                    'created_by' => $actorId,
                ]);
            }

            if ($status === 'posted') {
                $entry->transitionTo('posted', $actorId === null ? null : User::find($actorId));
            }

            return $entry->load('journalLines');
        });
    }

    private function assertBalanced(JournalDocument $document): void
    {
        if ($document->postings === []) {
            throw new UnbalancedJournal('A journal entry needs at least one line.');
        }

        $debits = BigDecimal::zero();
        $credits = BigDecimal::zero();

        foreach ($document->postings as $posting) {
            $amount = $this->money($posting->amount);

            // A line carries a debit or a credit, never both and never a
            // negative: the journal_lines_single_side CHECK enforces the same
            // rule in the database. Flip the side instead of negating.
            if (! $amount->isPositive()) {
                throw new UnbalancedJournal(sprintf(
                    'Journal line for posting key "%s" must be a positive amount, got %s.',
                    $posting->postingKey,
                    $amount,
                ));
            }

            $posting->isDebit
                ? $debits = $debits->plus($amount)
                : $credits = $credits->plus($amount);
        }

        if (! $debits->isEqualTo($credits)) {
            throw new UnbalancedJournal(sprintf(
                'Journal entry for "%s" does not balance: debits %s, credits %s.',
                $document->documentType,
                $debits,
                $credits,
            ));
        }
    }

    /** @return array<string, string> posting key => account ID */
    private function accountsFor(JournalDocument $document): array
    {
        $keys = $document->postingKeys();

        $accounts = JournalMapping::query()
            ->where('document_type', $document->documentType)
            ->whereIn('posting_key', $keys)
            ->pluck('account_id', 'posting_key')
            ->all();

        $missing = array_values(array_diff($keys, array_keys($accounts)));
        if ($missing !== []) {
            throw new UnmappedAccount(sprintf(
                'journal_mappings has no account for document type "%s", posting key(s) %s. Map them for this client rather than hardcoding an account code.',
                $document->documentType,
                implode(', ', $missing),
            ));
        }

        return $accounts;
    }

    private function money(BigDecimal $value): BigDecimal
    {
        return $value->toScale(self::MONEY_SCALE, RoundingMode::HALF_UP);
    }
}
