<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\JournalEntry;
use App\Models\User;
use App\Support\Documents\Exceptions\DocumentFrozen;
use App\Support\Documents\Exceptions\IllegalTransition;
use App\Support\Documents\Exceptions\TransitionNotPermitted;
use App\Support\Posting\JournalDocument;
use App\Support\Posting\JournalPoster;
use App\Support\Posting\JournalPosting;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DocumentStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
            JournalMappingsSeeder::class,
            NumberSequencesSeeder::class,
        ]);
    }

    public function test_a_declared_transition_moves_the_document(): void
    {
        $entry = $this->draft();

        $entry->transitionTo('posted', $this->userWithRole('Finance Manager'));

        $this->assertSame('posted', $entry->fresh()->status);
    }

    public function test_an_undeclared_transition_is_refused(): void
    {
        $entry = $this->draft();

        $this->expectException(IllegalTransition::class);
        $this->expectExceptionMessageMatches('/"draft" to "reversed"/');

        $entry->transitionTo('reversed', $this->userWithRole('Finance Manager'));
    }

    public function test_a_transition_the_actor_may_not_make_is_refused(): void
    {
        $entry = $this->draft();

        try {
            $entry->transitionTo('posted', $this->userWithRole('Sales Admin'));
            $this->fail('Expected the posting permission to be required.');
        } catch (TransitionNotPermitted) {
            $this->assertSame('draft', $entry->fresh()->status);
        }
    }

    public function test_the_administrator_wildcard_grants_the_transition(): void
    {
        $entry = $this->draft();

        $entry->transitionTo('posted', $this->userWithRole('Administrator'));

        $this->assertSame('posted', $entry->fresh()->status);
    }

    public function test_a_status_assigned_by_hand_is_refused(): void
    {
        $entry = $this->draft();
        $entry->status = 'posted';

        $this->expectException(IllegalTransition::class);
        $this->expectExceptionMessageMatches('/transitionTo/');

        $entry->save();
    }

    public function test_a_document_created_without_a_status_starts_at_the_declared_initial(): void
    {
        $entry = JournalEntry::create([
            'document_number' => 'JE/TEST/'.Str::random(6),
            'entry_date' => '2026-08-26',
            'ref_type' => 'manual',
        ]);

        $this->assertSame('draft', $entry->fresh()->status);
    }

    public function test_an_unrecognised_status_never_reaches_the_database(): void
    {
        $this->expectException(IllegalTransition::class);

        JournalEntry::create([
            'document_number' => 'JE/TEST/'.Str::random(6),
            'entry_date' => '2026-08-25',
            'ref_type' => 'manual',
            'status' => 'approved',
        ]);
    }

    public function test_a_posted_document_can_no_longer_be_edited_or_deleted(): void
    {
        $entry = $this->postedThroughTheKernel();

        try {
            $entry->description = 'Corrected after the fact';
            $entry->save();
            $this->fail('Expected a posted entry to be frozen.');
        } catch (DocumentFrozen) {
            $this->assertNull($entry->fresh()->description);
        }

        $this->expectException(DocumentFrozen::class);
        $entry->delete();
    }

    public function test_a_posted_document_may_still_transition(): void
    {
        // Frozen means its own fields no longer change, not that it is stuck:
        // reversal (1.6) is a transition, and the counter-entry carries the
        // correction.
        $entry = $this->postedThroughTheKernel();

        $entry->transitionTo('reversed');

        $this->assertSame('reversed', $entry->fresh()->status);
    }

    private function draft(): JournalEntry
    {
        return JournalEntry::create([
            'document_number' => 'JE/TEST/'.Str::random(6),
            'entry_date' => '2026-08-25',
            'description' => null,
            'ref_type' => 'manual',
            'status' => 'draft',
        ]);
    }

    private function postedThroughTheKernel(): JournalEntry
    {
        return app(JournalPoster::class)->post(new JournalDocument(
            documentType: 'goods_receipt',
            referenceId: (string) Str::uuid7(),
            entryDate: new DateTimeImmutable('2026-08-25'),
            postings: [
                JournalPosting::debit('inventory', '100.00'),
                JournalPosting::credit('grir', '100.00'),
            ],
        ));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', $role)->value('id'));

        return $user;
    }
}
