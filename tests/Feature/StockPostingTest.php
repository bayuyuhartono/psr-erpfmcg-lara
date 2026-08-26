<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Product;
use App\Support\Posting\Exceptions\InsufficientStock;
use App\Support\Posting\Exceptions\UnmappedAccount;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use App\Support\Posting\StockPostingResult;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StockPostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Accounts and their mapping are configuration: without them a stock
        // movement has nowhere to post, and StockPosting says so loudly.
        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
    }

    public function test_receipts_converge_on_the_correct_moving_average(): void
    {
        [$warehouse, $product] = $this->stockContext();

        $this->postMovement($warehouse, $product, '10', '100');
        $result = $this->postMovement($warehouse, $product, '10', '200');

        $this->assertSame('20.0000', (string) $result->productCost->total_qty);
        $this->assertSame('3000.00', (string) $result->productCost->total_value);
        $this->assertSame('150.0000', (string) $result->productCost->average_cost);
        $this->assertSame('20.0000', (string) $result->balance->qty_base);
        $this->assertSame('3000.00', (string) $result->balance->value);
        $this->assertDatabaseCount('stock_ledger', 2);

        $this->assertSame('posted', $result->journal->status);
        $this->assertSame('goods_receipt', $result->journal->ref_type);
        $this->assertSame(['1103' => '2000.00'], $this->debits($result->journal));
        $this->assertSame(['2101' => '2000.00'], $this->credits($result->journal));
    }

    public function test_issue_uses_moving_average_and_reduces_value_atomically(): void
    {
        [$warehouse, $product] = $this->stockContext();
        $this->postMovement($warehouse, $product, '10', '100');
        $this->postMovement($warehouse, $product, '10', '200');

        $result = $this->postMovement($warehouse, $product, '-4', null);

        $this->assertSame('150.0000', (string) $result->ledger->unit_cost);
        $this->assertSame('-600.00', (string) $result->ledger->value);
        $this->assertSame('16.0000', (string) $result->productCost->total_qty);
        $this->assertSame('2400.00', (string) $result->productCost->total_value);
        $this->assertSame('150.0000', (string) $result->productCost->average_cost);

        // The issue moves inventory out at the same value the ledger recorded.
        $this->assertSame(['5101' => '600.00'], $this->debits($result->journal));
        $this->assertSame(['1103' => '600.00'], $this->credits($result->journal));
    }

    public function test_insufficient_issue_rolls_back_every_stock_write(): void
    {
        [$warehouse, $product] = $this->stockContext();
        $this->postMovement($warehouse, $product, '2', '100');

        try {
            $this->postMovement($warehouse, $product, '-3', null);
            $this->fail('Expected insufficient stock exception.');
        } catch (InsufficientStock) {
            $this->assertDatabaseCount('stock_ledger', 1);
            // Ledger, balance, cost and journal commit together or not at all.
            $this->assertDatabaseCount('journal_entries', 1);
            $this->assertSame('2.0000', (string) ProductCost::where('product_id', $product->id)->firstOrFail()->total_qty);
            $this->assertSame('2.0000', (string) StockBalance::firstOrFail()->qty_base);
        }
    }

    public function test_consigned_stock_is_balanced_but_does_not_change_owned_cost(): void
    {
        [$warehouse, $product] = $this->stockContext();
        $owner = BusinessPartner::factory()->create(['is_principal' => true]);
        $movement = new StockMovement(
            $warehouse->id, $product->id, '5', '75', 'goods_receipt', (string) Str::uuid7(),
            new DateTimeImmutable('2026-08-25'), ownerId: $owner->id,
        );

        app(StockPosting::class)->post($movement);

        $this->assertSame('0.0000', (string) ProductCost::where('product_id', $product->id)->firstOrFail()->total_qty);
        $this->assertSame(0, StockBalance::count());
        $this->assertSame(1, StockBalance::includingConsigned()->count());
        $this->assertSame(0, StockLedger::count());
        $this->assertSame(1, StockLedger::includingConsigned()->count());
        // The goods are the principal's: they never touch our inventory
        // account, and the payable appears only at settlement.
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_a_movement_without_a_mapped_account_refuses_to_post(): void
    {
        [$warehouse, $product] = $this->stockContext();
        DB::table('journal_mappings')->where('document_type', 'goods_receipt')->delete();

        $this->expectException(UnmappedAccount::class);

        try {
            $this->postMovement($warehouse, $product, '10', '100');
        } finally {
            $this->assertDatabaseCount('stock_ledger', 0);
            $this->assertDatabaseCount('stock_balances', 0);
        }
    }

    public function test_warehouse_transfers_post_no_journal(): void
    {
        [$warehouse, $product] = $this->stockContext();
        $this->postMovement($warehouse, $product, '10', '100');
        $other = Warehouse::factory()->create();

        $out = app(StockPosting::class)->post(new StockMovement(
            $warehouse->id, $product->id, '-4', null, 'transfer_out',
            (string) Str::uuid7(), new DateTimeImmutable('2026-08-25'),
        ));
        $in = app(StockPosting::class)->post(new StockMovement(
            $other->id, $product->id, '4', '100', 'transfer_in',
            (string) Str::uuid7(), new DateTimeImmutable('2026-08-25'),
        ));

        // Both legs hit the same inventory account, so the entry would be a
        // no-op. Only the goods receipt posted a journal.
        $this->assertNull($out->journal);
        $this->assertNull($in->journal);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    /** @return array<string, string> account code => debit */
    private function debits(JournalEntry $entry): array
    {
        return $this->sideOf($entry, 'debit');
    }

    /** @return array<string, string> account code => credit */
    private function credits(JournalEntry $entry): array
    {
        return $this->sideOf($entry, 'credit');
    }

    /** @return array<string, string> */
    private function sideOf(JournalEntry $entry, string $side): array
    {
        return $entry->journalLines
            ->filter(fn ($line) => $line->{$side}->isPositive())
            ->mapWithKeys(fn ($line) => [$line->account->code => (string) $line->{$side}])
            ->all();
    }

    /** @return array{Warehouse, Product} */
    private function stockContext(): array
    {
        return [Warehouse::factory()->create(), Product::factory()->create()];
    }

    private function postMovement(Warehouse $warehouse, Product $product, string $quantity, ?string $cost): StockPostingResult
    {
        return app(StockPosting::class)->post(new StockMovement(
            $warehouse->id,
            $product->id,
            $quantity,
            $cost,
            $quantity[0] === '-' ? 'delivery' : 'goods_receipt',
            (string) Str::uuid7(),
            new DateTimeImmutable('2026-08-25'),
        ));
    }
}
