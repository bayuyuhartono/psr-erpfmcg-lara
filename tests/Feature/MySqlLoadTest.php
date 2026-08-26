<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Product;
use App\Domain\Purchasing\Actions\DraftGoodsReceipt;
use App\Domain\Purchasing\Actions\PostGoodsReceipt;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

/**
 * Twenty-five people working at once (NFR, 9.7).
 *
 * Real processes against the real database, because that is the only way the
 * locks are real: `product_costs` row locks, the journal number sequence, and
 * the posting claim all serialise work that threads sharing a connection would
 * never contend for.
 *
 * A goods receipt is the heaviest thing the system does — ledger, balance,
 * moving average under a row lock, journal, and a claim — so it is what gets
 * measured. Everything else is lighter.
 *
 * No `RefreshDatabase`: its transaction is never committed, and a worker in
 * another process would see none of it.
 */
final class MySqlLoadTest extends TestCase
{
    private const USERS = 25;

    private const DOCUMENTS_EACH = 4;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the dedicated MySQL test database.');
        }

        Artisan::call('migrate:fresh');
        $this->seed([
            CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class,
        ]);
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh');

        parent::tearDown();
    }

    #[Group('slow')]
    public function test_twenty_five_users_posting_different_products(): void
    {
        // The ordinary case: a warehouse team receiving different deliveries.
        // Nothing contends on `product_costs`, so what is left is the journal
        // number sequence — the one global lock every posting takes.
        $result = $this->load(sharedProduct: false);

        $this->report('25 users, different products', $result);
        $this->assertSuccessful($result);
    }

    #[Group('slow')]
    public function test_twenty_five_users_posting_the_same_product(): void
    {
        // The pessimal case, and the one moving average exists to survive:
        // every posting fighting for the same `product_costs` row.
        $result = $this->load(sharedProduct: true);

        $this->report('25 users, one product', $result);
        $this->assertSuccessful($result);

        // The whole point of the row lock: every unit is accounted for.
        $expected = self::USERS * self::DOCUMENTS_EACH * 10;
        $this->assertSame(
            $expected.'.0000',
            (string) DB::table('product_costs')->value('total_qty'),
            'Moving average lost quantity under load',
        );
    }

    /** @param  array{documents: int, failures: array<string, int>, seconds: float, latencies: list<float>}  $result */
    private function assertSuccessful(array $result): void
    {
        $this->assertSame([], $result['failures'], 'Work failed under load: '.json_encode($result['failures']));

        $expected = self::USERS * self::DOCUMENTS_EACH;
        $this->assertSame($expected, $result['documents'], 'Documents went missing under load');
        // Nothing posted twice: one claim per document.
        $this->assertSame($expected, (int) DB::table('document_postings')->count());
    }

    /** @return array{documents: int, failures: array<string, int>, seconds: float, latencies: list<float>} */
    private function load(bool $sharedProduct): array
    {
        $supplier = BusinessPartner::factory()->create(['is_supplier' => true]);
        $warehouse = Warehouse::factory()->create();
        $shared = Product::factory()->create(['sku' => 'SHARED-01', 'base_uom' => 'PCS']);

        $products = [];
        foreach (range(0, self::USERS - 1) as $worker) {
            $products[$worker] = $sharedProduct
                ? $shared->id
                : Product::factory()->create(['sku' => sprintf('LOAD-%03d', $worker), 'base_uom' => 'PCS'])->id;
        }

        $log = tempnam(sys_get_temp_dir(), 'load').'.jsonl';
        DB::disconnect();

        $started = microtime(true);
        $children = [];

        foreach (range(0, self::USERS - 1) as $worker) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                DB::reconnect();
                $this->work($worker, $supplier->id, $warehouse->id, $products[$worker], $log);
                exit(0);
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $seconds = microtime(true) - $started;
        DB::reconnect();

        $documents = 0;
        $failures = [];
        $latencies = [];

        foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);

            if (($entry['ok'] ?? false) === true) {
                $documents++;
                $latencies[] = (float) $entry['ms'];

                continue;
            }

            $failures[$entry['error'] ?? 'unknown'] = ($failures[$entry['error'] ?? 'unknown'] ?? 0) + 1;
        }

        @unlink($log);

        return compact('documents', 'failures', 'seconds', 'latencies');
    }

    private function work(int $worker, string $supplierId, string $warehouseId, string $productId, string $log): void
    {
        $supplier = BusinessPartner::find($supplierId);
        $product = Product::find($productId);
        $draft = app(DraftGoodsReceipt::class);
        $post = app(PostGoodsReceipt::class);

        foreach (range(1, self::DOCUMENTS_EACH) as $n) {
            $started = microtime(true);

            try {
                // Through the module actions, not the kernel directly — which
                // means the posting runs inside the Action's own transaction,
                // exactly as known issue 5 describes.
                $receipt = $draft($supplier, $warehouseId, [
                    ['product' => $product, 'qty' => '10', 'unit_cost' => (string) (1000 + $worker)],
                ], null, new CarbonImmutable('2026-08-26'));

                $post($receipt);

                $entry = ['ok' => true, 'ms' => (microtime(true) - $started) * 1000];
            } catch (Throwable $exception) {
                $entry = ['ok' => false, 'error' => class_basename($exception).': '.mb_substr($exception->getMessage(), 0, 120)];
            }

            file_put_contents($log, json_encode($entry).PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    /** @param  array{documents: int, failures: array<string, int>, seconds: float, latencies: list<float>}  $result */
    private function report(string $label, array $result): void
    {
        $latencies = $result['latencies'];
        sort($latencies);
        $count = count($latencies);

        fwrite(STDERR, sprintf(
            "\n  %s\n    %d documents in %.1fs — %.1f/sec, median %.0f ms, p95 %.0f ms, slowest %.0f ms, %d failure(s)\n",
            $label,
            $result['documents'],
            $result['seconds'],
            $result['documents'] / max(0.001, $result['seconds']),
            $count ? $latencies[(int) ($count * 0.5)] : 0,
            $count ? $latencies[min($count - 1, (int) ($count * 0.95))] : 0,
            $count ? end($latencies) : 0,
            array_sum($result['failures']),
        ));
    }
}
