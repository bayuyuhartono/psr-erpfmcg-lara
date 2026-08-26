<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\Product;
use App\Support\Posting\Exceptions\AlreadyReversed;
use App\Support\Posting\Exceptions\InsufficientStock;
use App\Support\Posting\Exceptions\NothingToReverse;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use App\Support\Posting\StockReversal;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StockReversalTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
        $this->warehouse = Warehouse::factory()->create();
        $this->product = Product::factory()->create();
    }

    public function test_reversing_a_receipt_backs_out_its_own_value_not_todays_average(): void
    {
        $this->receipt('10', '100');
        $second = $this->receipt('10', '200');

        // Average is now 150. Backing the second receipt out at 150 would
        // leave 10 units valued at 1,500 — the receipt would have silently
        // repriced the stock that was already on hand.
        app(StockReversal::class)->reverse('goods_receipt', $second);

        $cost = ProductCost::where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('10.0000', (string) $cost->total_qty);
        $this->assertSame('1000.00', (string) $cost->total_value);
        $this->assertSame('100.0000', (string) $cost->average_cost);
        $this->assertSame('1000.00', (string) StockBalance::firstOrFail()->value);
    }

    public function test_reversal_appends_a_mirror_row_and_edits_nothing(): void
    {
        $reference = $this->receipt('10', '100');
        $original = StockLedger::firstOrFail();

        app(StockReversal::class)->reverse('goods_receipt', $reference);

        $this->assertDatabaseCount('stock_ledger', 2);
        $this->assertEquals($original->getAttributes(), $original->fresh()->getAttributes());

        $mirror = StockLedger::where('reverses_ledger_id', $original->id)->firstOrFail();
        $this->assertSame('-10.0000', (string) $mirror->qty_base);
        $this->assertSame('100.0000', (string) $mirror->unit_cost);
        $this->assertSame('-1000.00', (string) $mirror->value);
        $this->assertSame($original->ref_id, $mirror->ref_id);
    }

    public function test_the_original_journal_is_marked_reversed_and_the_counter_entry_mirrors_it(): void
    {
        $reference = $this->receipt('10', '100');
        $original = JournalEntry::firstOrFail();

        $result = app(StockReversal::class)->reverse('goods_receipt', $reference);

        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertCount(1, $result->reversedEntries);

        $counter = $result->movements[0]->journal;
        $this->assertSame('posted', $counter->status);
        $this->assertStringContainsString('Reversal of goods_receipt', $counter->description);

        // Every account the original debited, the counter-entry credits.
        $this->assertEquals(['1103' => 'debit', '2101' => 'credit'], $this->sides($original));
        $this->assertEquals(['1103' => 'credit', '2101' => 'debit'], $this->sides($counter));
        $this->assertSame(
            $original->journalLines->sum(fn ($line) => (string) $line->debit),
            $counter->journalLines->sum(fn ($line) => (string) $line->credit),
        );
    }

    public function test_goods_return_at_the_cost_they_left_at(): void
    {
        $this->receipt('10', '100');
        $delivery = $this->issue('-5');
        $this->receipt('10', '200');

        // Stock is now 15 units worth 2,500, averaging 166.6667. The five
        // units left at 100, so that is what they must come back at.
        app(StockReversal::class)->reverse('delivery', $delivery);

        $cost = ProductCost::where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('20.0000', (string) $cost->total_qty);
        $this->assertSame('3000.00', (string) $cost->total_value);
        $this->assertSame('150.0000', (string) $cost->average_cost);
    }

    public function test_a_document_cannot_be_reversed_twice(): void
    {
        $reference = $this->receipt('10', '100');
        app(StockReversal::class)->reverse('goods_receipt', $reference);

        $this->expectException(AlreadyReversed::class);

        try {
            app(StockReversal::class)->reverse('goods_receipt', $reference);
        } finally {
            $this->assertDatabaseCount('stock_ledger', 2);
        }
    }

    public function test_reversing_a_document_that_posted_no_stock_is_refused(): void
    {
        $this->expectException(NothingToReverse::class);

        app(StockReversal::class)->reverse('goods_receipt', (string) Str::uuid7());
    }

    public function test_a_receipt_whose_goods_have_shipped_cannot_be_reversed(): void
    {
        $reference = $this->receipt('10', '100');
        $this->issue('-8');

        try {
            app(StockReversal::class)->reverse('goods_receipt', $reference);
            $this->fail('Expected the reversal to run out of stock.');
        } catch (InsufficientStock) {
            // The whole reversal rolls back: no mirror rows, no counter-journal,
            // and the original journal is still posted.
            $this->assertDatabaseCount('stock_ledger', 2);
            $this->assertSame(0, StockLedger::whereNotNull('reverses_ledger_id')->count());
            $this->assertSame(2, JournalEntry::where('status', 'posted')->count());
            $this->assertSame('2.0000', (string) StockBalance::firstOrFail()->qty_base);
        }
    }

    private function receipt(string $quantity, string $unitCost): string
    {
        $reference = (string) Str::uuid7();
        app(StockPosting::class)->post(new StockMovement(
            $this->warehouse->id, $this->product->id, $quantity, $unitCost, 'goods_receipt',
            $reference, new DateTimeImmutable('2026-08-25'),
        ));

        return $reference;
    }

    private function issue(string $quantity): string
    {
        $reference = (string) Str::uuid7();
        app(StockPosting::class)->post(new StockMovement(
            $this->warehouse->id, $this->product->id, $quantity, null, 'delivery',
            $reference, new DateTimeImmutable('2026-08-25'),
        ));

        return $reference;
    }

    /** @return array<string, string> account code => "debit/credit" */
    private function sides(JournalEntry $entry): array
    {
        return $entry->journalLines()->with('account')->get()
            ->mapWithKeys(fn ($line) => [
                $line->account->code => $line->debit->isPositive() ? 'debit' : 'credit',
            ])->all();
    }
}
