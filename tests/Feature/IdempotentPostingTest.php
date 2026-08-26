<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\Product;
use App\Support\Posting\Exceptions\AlreadyPosted;
use App\Support\Posting\Exceptions\InsufficientStock;
use App\Support\Posting\Exceptions\InvalidStockMovement;
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

final class IdempotentPostingTest extends TestCase
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

    public function test_a_document_posts_every_line_in_one_go(): void
    {
        $reference = (string) Str::uuid7();

        $posted = app(StockPosting::class)->postDocument([
            $this->movement($reference, '10', '100'),
            $this->movement($reference, '4', '250'),
        ]);

        $this->assertSame(2, $posted);
        $this->assertDatabaseCount('stock_ledger', 2);
        $this->assertSame('14.0000', (string) ProductCost::firstOrFail()->total_qty);
        $this->assertDatabaseHas('document_postings', ['ref_type' => 'goods_receipt', 'ref_id' => $reference]);
    }

    public function test_submitting_the_same_document_twice_posts_it_once(): void
    {
        $reference = (string) Str::uuid7();
        $lines = [$this->movement($reference, '10', '100')];

        app(StockPosting::class)->postDocument($lines);

        try {
            app(StockPosting::class)->postDocument($lines);
            $this->fail('Expected the second submission to be refused.');
        } catch (AlreadyPosted) {
            $this->assertDatabaseCount('stock_ledger', 1);
            $this->assertDatabaseCount('journal_entries', 1);
            $this->assertSame('10.0000', (string) ProductCost::firstOrFail()->total_qty);
        }
    }

    public function test_a_posting_that_fails_leaves_no_claim_behind(): void
    {
        $reference = (string) Str::uuid7();

        try {
            app(StockPosting::class)->postDocument([
                $this->movement($reference, '10', '100', 'adjustment'),
                $this->movement($reference, '-40', null, 'adjustment'),
            ]);
            $this->fail('Expected the second line to run out of stock.');
        } catch (InsufficientStock) {
            $this->assertDatabaseCount('document_postings', 0);
            $this->assertDatabaseCount('stock_ledger', 0);
        }

        // Corrected and resubmitted under the same document number, which is
        // what a user who fixed a typo would do.
        app(StockPosting::class)->postDocument([
            $this->movement($reference, '10', '100', 'adjustment'),
            $this->movement($reference, '-4', null, 'adjustment'),
        ]);

        $this->assertSame('6.0000', (string) ProductCost::firstOrFail()->total_qty);
    }

    public function test_movements_from_different_documents_are_refused(): void
    {
        $this->expectException(InvalidStockMovement::class);
        $this->expectExceptionMessageMatches('/same document/');

        app(StockPosting::class)->postDocument([
            $this->movement((string) Str::uuid7(), '10', '100'),
            $this->movement((string) Str::uuid7(), '10', '100'),
        ]);
    }

    public function test_an_empty_document_is_refused(): void
    {
        $this->expectException(InvalidStockMovement::class);

        app(StockPosting::class)->postDocument([]);
    }

    public function test_reversal_is_not_blocked_by_the_documents_own_claim(): void
    {
        $reference = (string) Str::uuid7();
        app(StockPosting::class)->postDocument([$this->movement($reference, '10', '100')]);

        app(StockReversal::class)->reverse('goods_receipt', $reference);

        $this->assertSame('0.0000', (string) ProductCost::firstOrFail()->total_qty);
        $this->assertSame(1, StockLedger::whereNotNull('reverses_ledger_id')->count());
        // The claim stands: a reversed document is not free to post again. A
        // correction is a new document.
        $this->assertDatabaseCount('document_postings', 1);
        $this->assertSame(1, JournalEntry::where('status', 'reversed')->count());

        $this->expectException(AlreadyPosted::class);
        app(StockPosting::class)->postDocument([$this->movement($reference, '10', '100')]);
    }

    private function movement(string $reference, string $quantity, ?string $unitCost, string $referenceType = 'goods_receipt'): StockMovement
    {
        return new StockMovement(
            $this->warehouse->id,
            $this->product->id,
            $quantity,
            $unitCost,
            $referenceType,
            $reference,
            new DateTimeImmutable('2026-08-26'),
        );
    }
}
