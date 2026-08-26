<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\JournalEntry;
use App\Support\Posting\Exceptions\UnbalancedJournal;
use App\Support\Posting\Exceptions\UnmappedAccount;
use App\Support\Posting\JournalDocument;
use App\Support\Posting\JournalPoster;
use App\Support\Posting\JournalPosting;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class JournalPosterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
    }

    public function test_posting_keys_resolve_to_the_accounts_this_client_mapped(): void
    {
        $entry = $this->postEntry([
            JournalPosting::debit('inventory', '1500.00'),
            JournalPosting::credit('grir', '1500.00'),
        ]);

        $this->assertSame('posted', $entry->status);
        $this->assertSame('goods_receipt', $entry->ref_type);
        $this->assertCount(2, $entry->journalLines);

        $lines = $entry->journalLines->keyBy(fn ($line) => $line->account->code);
        $this->assertSame('1500.00', (string) $lines['1103']->debit);
        $this->assertSame('0.00', (string) $lines['1103']->credit);
        $this->assertSame('1500.00', (string) $lines['2103']->credit);
        $this->assertSame('0.00', (string) $lines['2103']->debit);
    }

    public function test_a_remapped_account_changes_where_the_same_posting_lands(): void
    {
        // The per-client escape hatch: configuration, never a code change.
        $variance = DB::table('chart_of_accounts')->where('code', '5102')->value('id');
        DB::table('journal_mappings')
            ->where(['document_type' => 'goods_receipt', 'posting_key' => 'grir'])
            ->update(['account_id' => $variance]);

        $entry = $this->postEntry([
            JournalPosting::debit('inventory', '10.00'),
            JournalPosting::credit('grir', '10.00'),
        ]);

        $this->assertSame(
            ['1103', '5102'],
            $entry->journalLines->map(fn ($line) => $line->account->code)->sort()->values()->all(),
        );
    }

    public function test_the_entry_takes_its_number_from_the_locked_sequence(): void
    {
        $first = $this->postEntry([JournalPosting::debit('inventory', '1.00'), JournalPosting::credit('grir', '1.00')]);
        $second = $this->postEntry([JournalPosting::debit('inventory', '1.00'), JournalPosting::credit('grir', '1.00')]);

        $this->assertSame('JE/2608/000001', $first->document_number);
        $this->assertSame('JE/2608/000002', $second->document_number);
    }

    public function test_an_unbalanced_entry_is_refused_before_anything_is_written(): void
    {
        $this->expectException(UnbalancedJournal::class);

        try {
            $this->postEntry([
                JournalPosting::debit('inventory', '100.00'),
                JournalPosting::credit('grir', '99.99'),
            ]);
        } finally {
            $this->assertDatabaseCount('journal_entries', 0);
            $this->assertDatabaseCount('journal_lines', 0);
        }
    }

    public function test_a_negative_amount_is_refused_rather_than_flipped(): void
    {
        // journal_lines_single_side enforces the same rule in the database:
        // to reverse a posting, swap the side, never negate the amount.
        $this->expectException(UnbalancedJournal::class);

        $this->postEntry([
            JournalPosting::debit('inventory', '-100.00'),
            JournalPosting::credit('grir', '-100.00'),
        ]);
    }

    public function test_an_unmapped_posting_key_names_what_is_missing(): void
    {
        DB::table('journal_mappings')
            ->where(['document_type' => 'goods_receipt', 'posting_key' => 'grir'])
            ->delete();

        $this->expectException(UnmappedAccount::class);
        $this->expectExceptionMessageMatches('/goods_receipt.*grir/');

        $this->postEntry([
            JournalPosting::debit('inventory', '100.00'),
            JournalPosting::credit('grir', '100.00'),
        ]);
    }

    /** @param list<JournalPosting> $postings */
    private function postEntry(array $postings): JournalEntry
    {
        return app(JournalPoster::class)->post(new JournalDocument(
            documentType: 'goods_receipt',
            referenceId: (string) Str::uuid7(),
            entryDate: new DateTimeImmutable('2026-08-25'),
            postings: $postings,
        ));
    }
}
