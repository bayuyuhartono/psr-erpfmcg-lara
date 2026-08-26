<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Filament\Resources\AccountingPeriods\AccountingPeriodResource;
use App\Filament\Resources\AccountingPeriods\Pages\CreateAccountingPeriod;
use App\Filament\Resources\AccountingPeriods\Pages\ListAccountingPeriods;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Models\User;
use App\Support\Posting\JournalDocument;
use App\Support\Posting\JournalPoster;
use App\Support\Posting\JournalPosting;
use Brick\Math\BigDecimal;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use DateTimeImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Both of these screens were generated and left with an empty `components([])`,
 * so they rendered as a blank form over a blank table and nobody noticed. What
 * these tests hold is that they show their fields.
 */
final class AccountingScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', 'Administrator')->value('id'));
        $this->actingAs($user);
    }

    public function test_the_period_list_shows_its_columns(): void
    {
        AccountingPeriod::query()->create(['year' => 2026, 'month' => 3, 'status' => 'closed']);
        AccountingPeriod::query()->create(['year' => 2026, 'month' => 4, 'status' => 'open']);

        Livewire::test(ListAccountingPeriods::class)
            ->assertCanSeeTableRecords(AccountingPeriod::query()->get())
            ->assertTableColumnExists('year')
            ->assertTableColumnExists('month')
            ->assertTableColumnExists('status')
            // Most recent first: the month being asked about is the one that
            // just ended.
            ->assertCanRenderTableColumn('closed_at');
    }

    public function test_a_period_is_created_through_the_form(): void
    {
        Livewire::test(CreateAccountingPeriod::class)
            ->fillForm(['year' => 2026, 'month' => 7])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('accounting_periods', [
            'year' => 2026,
            'month' => 7,
            'status' => 'open',   // never chosen in the form; closing runs checks
        ]);
    }

    public function test_a_journal_entry_can_be_viewed_with_its_lines(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(JournalMappingsSeeder::class);
        $this->seed(NumberSequencesSeeder::class);

        $entry = app(JournalPoster::class)->post(new JournalDocument(
            documentType: 'opening_balance',
            referenceId: (string) Str::uuid7(),
            entryDate: new DateTimeImmutable('2026-08-01'),
            description: 'Opening the books',
            postings: [
                JournalPosting::debit('inventory', BigDecimal::of('1000.00')),
                JournalPosting::credit('opening_equity', BigDecimal::of('1000.00')),
            ],
        ));

        Livewire::test(ListJournalEntries::class)
            ->assertCanSeeTableRecords([$entry])
            // Mounted, not called: a view action has no work to do, it opens
            // a modal — and the modal renders in its own pass, so a plain
            // assertSee on the page would miss it however well it worked.
            ->mountAction(TestAction::make('view')->table($entry))
            ->assertActionMounted(TestAction::make('view')->table($entry))
            ->assertMountedActionModalSee('Opening the books')
            ->assertMountedActionModalSee('1103')   // account codes, from the lines
            ->assertMountedActionModalSee('3102');
    }

    public function test_the_accounting_list_screens_load(): void
    {
        foreach ([AccountingPeriodResource::class, JournalEntryResource::class] as $resource) {
            $this->get($resource::getUrl('index'))->assertSuccessful();
        }
    }
}
