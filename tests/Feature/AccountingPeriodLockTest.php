<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\Product;
use App\Models\User;
use App\Support\Documents\Exceptions\IllegalTransition;
use App\Support\Documents\Exceptions\TransitionNotPermitted;
use App\Support\Posting\Exceptions\ClosedPeriod;
use App\Support\Posting\JournalDocument;
use App\Support\Posting\JournalPoster;
use App\Support\Posting\JournalPosting;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use App\Support\Posting\StockReversal;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountingPeriodLockTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
            JournalMappingsSeeder::class,
            NumberSequencesSeeder::class,
        ]);
        $this->warehouse = Warehouse::factory()->create();
        $this->product = Product::factory()->create();
    }

    public function test_a_month_with_no_period_row_is_open(): void
    {
        // Locking is a decision someone makes. Not having made it is not a lock.
        $this->assertDatabaseCount('accounting_periods', 0);

        app(StockPosting::class)->postDocument([$this->receipt('2026-08-26')]);

        $this->assertDatabaseCount('stock_ledger', 1);
    }

    public function test_stock_cannot_be_backdated_into_a_closed_period(): void
    {
        $this->close(2026, 7);

        try {
            app(StockPosting::class)->postDocument([$this->receipt('2026-07-31')]);
            $this->fail('Expected the closed period to refuse the posting.');
        } catch (ClosedPeriod $exception) {
            $this->assertStringContainsString('July 2026', $exception->getMessage());
            $this->assertDatabaseCount('stock_ledger', 0);
            $this->assertDatabaseCount('journal_entries', 0);
            $this->assertDatabaseCount('document_postings', 0);
        }
    }

    public function test_an_open_period_still_accepts_backdated_postings(): void
    {
        AccountingPeriod::create(['year' => 2026, 'month' => 7]);

        app(StockPosting::class)->postDocument([$this->receipt('2026-07-31')]);

        $this->assertDatabaseCount('stock_ledger', 1);
    }

    public function test_a_manual_journal_cannot_reach_into_a_closed_period(): void
    {
        // Nothing to do with stock, and it must be stopped just the same.
        $this->close(2026, 7);

        $this->expectException(ClosedPeriod::class);

        app(JournalPoster::class)->post(new JournalDocument(
            documentType: 'goods_receipt',
            referenceId: (string) Str::uuid7(),
            entryDate: new DateTimeImmutable('2026-07-15'),
            postings: [
                JournalPosting::debit('inventory', '100.00'),
                JournalPosting::credit('grir', '100.00'),
            ],
        ));
    }

    public function test_a_correction_to_a_closed_month_posts_in_an_open_one(): void
    {
        $reference = (string) Str::uuid7();
        app(StockPosting::class)->postDocument([$this->receipt('2026-07-10', $reference)]);
        $this->close(2026, 7);

        // The original stands in July; the reversal lands in August, which is
        // where the correction was actually made.
        app(StockReversal::class)->reverse(
            'goods_receipt',
            $reference,
            date: new CarbonImmutable('2026-08-26'),
        );

        $this->assertSame('2026-07-10', DB::table('stock_ledger')->whereNull('reverses_ledger_id')->value('transaction_date'));
        $this->assertSame('2026-08-26', DB::table('stock_ledger')->whereNotNull('reverses_ledger_id')->value('transaction_date'));
    }

    public function test_closing_a_period_needs_the_permission_and_records_who(): void
    {
        $period = AccountingPeriod::create(['year' => 2026, 'month' => 8]);
        $this->assertSame('open', $period->status);

        try {
            $period->transitionTo('closed', $this->userWithRole('Sales Admin'));
            $this->fail('Expected closing to be permissioned.');
        } catch (TransitionNotPermitted) {
            $this->assertSame('open', $period->fresh()->status);
        }

        $finance = $this->userWithRole('Finance Manager');
        $period->transitionTo('closed', $finance, ['closed_by' => $finance->id, 'closed_at' => now()]);

        $this->assertSame('closed', $period->fresh()->status);
        $this->assertSame($finance->id, $period->fresh()->closed_by);
    }

    public function test_a_period_cannot_be_closed_by_assignment(): void
    {
        $period = AccountingPeriod::create(['year' => 2026, 'month' => 8]);
        $period->status = 'closed';

        $this->expectException(IllegalTransition::class);

        $period->save();
    }

    public function test_a_closed_period_can_be_reopened_and_then_accepts_postings(): void
    {
        $period = $this->close(2026, 8);

        $period->transitionTo('open', $this->userWithRole('Finance Manager'));

        app(StockPosting::class)->postDocument([$this->receipt('2026-08-26')]);
        $this->assertDatabaseCount('stock_ledger', 1);
    }

    private function receipt(string $date, ?string $reference = null): StockMovement
    {
        return new StockMovement(
            $this->warehouse->id,
            $this->product->id,
            '10',
            '100',
            'goods_receipt',
            $reference ?? (string) Str::uuid7(),
            new DateTimeImmutable($date),
        );
    }

    private function close(int $year, int $month): AccountingPeriod
    {
        $finance = $this->userWithRole('Finance Manager');

        return AccountingPeriod::create(['year' => $year, 'month' => $month])
            ->transitionTo('closed', $finance, ['closed_by' => $finance->id, 'closed_at' => now()]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', $role)->value('id'));

        return $user;
    }
}
