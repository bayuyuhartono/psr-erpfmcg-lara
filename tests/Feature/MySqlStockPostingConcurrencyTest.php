<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\Product;
use App\Support\Posting\Exceptions\AlreadyPosted;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

final class MySqlStockPostingConcurrencyTest extends TestCase
{
    public function test_a_double_submitted_document_posts_exactly_once(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the dedicated MySQL test database.');
        }

        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
        [$warehouseId, $productId] = $this->stockContext();
        $reference = (string) Str::uuid7();

        // Two requests for the same document, in flight at once — the second
        // click, the retried POST. A check-then-write would let both through.
        $outcomes = $this->inParallel(2, function () use ($warehouseId, $productId, $reference): int {
            try {
                app(StockPosting::class)->postDocument([new StockMovement(
                    $warehouseId, $productId, '10', '100', 'goods_receipt',
                    $reference, new DateTimeImmutable('2026-08-26'),
                )]);

                return 0;
            } catch (AlreadyPosted) {
                return 7;
            }
        });

        sort($outcomes);
        $this->assertSame([0, 7], $outcomes, 'Exactly one of the two submissions should have posted.');

        DB::reconnect();
        $this->assertSame(1, DB::table('stock_ledger')->where('product_id', $productId)->count());
        $this->assertSame('10.0000', (string) ProductCost::where('product_id', $productId)->firstOrFail()->total_qty);
        $this->assertSame(1, DB::table('document_postings')->where('ref_id', $reference)->count());

        $this->cleanUp($warehouseId, $productId);
    }

    public function test_parallel_receipts_converge_on_the_locked_average(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the dedicated MySQL test database.');
        }

        // Accounts, their mapping, and the journal sequence are configuration
        // that a real installation always has; seed them if this database was
        // migrated without them.
        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
        [$warehouseId, $productId] = $this->stockContext();

        $costs = ['100', '200'];
        $outcomes = $this->inParallel(2, function (int $index) use ($warehouseId, $productId, $costs): int {
            app(StockPosting::class)->post(new StockMovement(
                $warehouseId, $productId, '10', $costs[$index], 'goods_receipt',
                (string) Str::uuid7(), new DateTimeImmutable('2026-08-25'),
            ));

            return 0;
        });

        $this->assertSame([0, 0], $outcomes);

        DB::reconnect();
        $cost = ProductCost::where('product_id', $productId)->firstOrFail();
        $this->assertSame('20.0000', (string) $cost->total_qty);
        $this->assertSame('3000.00', (string) $cost->total_value);
        $this->assertSame('150.0000', (string) $cost->average_cost);

        // Both processes queued on the same number sequence row, so the two
        // entries took consecutive numbers rather than colliding.
        $referenceIds = DB::table('stock_ledger')->where('product_id', $productId)->pluck('ref_id');
        $entries = DB::table('journal_entries')->whereIn('ref_id', $referenceIds)->pluck('document_number');
        $this->assertCount(2, $entries);
        $this->assertSame(2, $entries->unique()->count());

        $this->cleanUp($warehouseId, $productId);
    }

    /** @return array{string, string} warehouse ID, product ID */
    private function stockContext(): array
    {
        // Through the models, because that is how products are created
        // everywhere in the application — and it is the model that gives a
        // product its `product_costs` row, which keeps the first movement of
        // its life off the insert path two processes can deadlock on.
        return [
            Warehouse::factory()->create(['code' => 'CON-'.Str::lower(Str::random(8))])->id,
            Product::factory()->create(['sku' => 'CON-'.Str::lower(Str::random(8)), 'base_uom' => 'PCS'])->id,
        ];
    }

    /**
     * Run $count real processes against the real database. Threads would share
     * a connection and prove nothing; these contend for the same row locks and
     * unique keys a second web request would.
     *
     * @param  callable(int): int  $work  returns the exit code for that child
     * @return list<int> each child's exit code
     */
    private function inParallel(int $count, callable $work): array
    {
        DB::disconnect();
        $children = [];

        for ($index = 0; $index < $count; $index++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::reconnect();
                    exit($work($index));
                } catch (Throwable $exception) {
                    fwrite(STDERR, $exception->getMessage().PHP_EOL);
                    exit(1);
                }
            }
            $children[] = $pid;
        }

        $outcomes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $outcomes[] = pcntl_wexitstatus($status);
        }

        return $outcomes;
    }

    private function cleanUp(string $warehouseId, string $productId): void
    {
        $referenceIds = DB::table('stock_ledger')->where('product_id', $productId)->pluck('ref_id');
        $entryIds = DB::table('journal_entries')->whereIn('ref_id', $referenceIds)->pluck('id');

        DB::table('journal_lines')->whereIn('journal_entry_id', $entryIds)->delete();
        DB::table('journal_entries')->whereIn('ref_id', $referenceIds)->delete();
        DB::table('document_postings')->whereIn('ref_id', $referenceIds)->delete();
        DB::table('stock_ledger')->where('product_id', $productId)->delete();
        DB::table('stock_balances')->where('product_id', $productId)->delete();
        DB::table('product_costs')->where('product_id', $productId)->delete();
        DB::table('products')->where('id', $productId)->delete();
        DB::table('warehouses')->where('id', $warehouseId)->delete();
    }
}
