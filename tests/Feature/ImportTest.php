<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Inventory\Actions\ResolveStockBatch;
use App\Domain\Inventory\Imports\OpeningStockImporter;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Imports\CustomerImporter;
use App\Domain\Masters\Imports\ProductImporter;
use App\Domain\Masters\Models\Brand;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\CustomerTier;
use App\Domain\Masters\Models\Product;
use App\Domain\Masters\Models\ProductUom;
use App\Filament\Pages\ImportData;
use App\Models\User;
use App\Support\Import\Exceptions\ImportHasProblems;
use App\Support\Import\SpreadsheetImporter;
use App\Support\Posting\StockPosting;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

final class ImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_the_template_carries_the_headings_the_importer_reads(): void
    {
        $importer = app(ProductImporter::class);
        $path = $this->tempFile();

        $importer->writeTemplate($path);
        $preview = $importer->preview($path);

        // The contract is column alignment: whatever a user types under a
        // heading has to arrive in the field that heading names. Read the
        // template back through the importer and check the example landed
        // where it says it did.
        $this->assertSame(1, $preview->rowCount);
        $this->assertSame($importer->example(), $preview->sample[0]->values);

        // And the only reason that example is not importable as-is is the
        // brand it refers to, which onboarding creates first.
        $this->assertSame(['Brand code'], array_map(fn ($p) => $p->column, $preview->problems));
    }

    public function test_products_import_with_their_selling_unit(): void
    {
        Brand::create(['code' => 'BR-KOPI', 'name' => 'Kopi Nusantara']);
        $path = $this->sheet(app(ProductImporter::class), [
            ['KOPI-01', 'Kopi Susu 200ml', 'PCS', 'BR-KOPI', 'Beverages', 'Y', 'Y', '365', '70', '30', '11', 'CARTON', '24'],
            ['TEH-01', 'Teh Botol 350ml', 'PCS', '', 'Beverages', 'N', 'N', '', '', '', '11', '', ''],
        ]);

        $written = app(ProductImporter::class)->import($path);

        $this->assertSame(2, $written);
        $this->assertSame('365', (string) Product::where('sku', 'KOPI-01')->value('shelf_life_days'));
        $this->assertSame('24.0000', (string) ProductUom::where('uom', 'CARTON')->value('qty_in_base'));
        // Both rows landed in the same category, created once on the way past.
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_one_bad_row_saves_nothing(): void
    {
        $path = $this->sheet(app(ProductImporter::class), [
            ['KOPI-01', 'Kopi Susu', 'PCS', '', '', 'N', 'N', '', '', '', '', '', ''],
            ['TEH-01', 'Teh Botol', 'PCS', 'NO-SUCH-BRAND', '', 'N', 'N', '', '', '', '', '', ''],
        ]);

        try {
            app(ProductImporter::class)->import($path);
            $this->fail('Expected the unknown brand to stop the import.');
        } catch (ImportHasProblems $exception) {
            $this->assertStringContainsString('no brand has the code "NO-SUCH-BRAND"', $exception->problems[0]->describe());
            $this->assertStringContainsString('Row 3', $exception->problems[0]->describe());
            // The good row is not saved either. That is the point.
            $this->assertDatabaseCount('products', 0);
        }
    }

    public function test_a_preview_reports_domain_failures_without_writing(): void
    {
        // Expiry tracked with no batch tracking is refused by the model, not
        // by any shape check — so only a real attempt can find it.
        $path = $this->sheet(app(ProductImporter::class), [
            ['KOPI-01', 'Kopi Susu', 'PCS', '', '', 'N', 'Y', '365', '', '', '', '', ''],
        ]);

        $preview = app(ProductImporter::class)->preview($path);

        $this->assertFalse($preview->isImportable());
        $this->assertStringContainsString('nowhere to record one', $preview->problems[0]->message);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_clean_preview_writes_nothing_either(): void
    {
        $path = $this->sheet(app(ProductImporter::class), [
            ['KOPI-01', 'Kopi Susu', 'PCS', '', '', 'N', 'N', '', '', '', '', '', ''],
        ]);

        $preview = app(ProductImporter::class)->preview($path);

        $this->assertTrue($preview->isImportable());
        $this->assertSame(1, $preview->rowCount);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_customers_import_with_their_exchange_schedule(): void
    {
        CustomerTier::create(['name' => 'Wholesale']);
        $path = $this->sheet(app(CustomerImporter::class), [
            ['C-00123', 'Toko Berkah', '', 'Ibu Sari', '0812', 'Wholesale', '25000000', '30', '1, 15', '10, 25'],
        ]);

        app(CustomerImporter::class)->import($path);

        $customer = BusinessPartner::where('code', 'C-00123')->firstOrFail();
        $this->assertTrue($customer->is_customer);
        $this->assertSame([1, 15], $customer->invoice_exchange_days);
        $this->assertSame('25000000.00', (string) $customer->credit_limit);
    }

    public function test_opening_stock_posts_one_document_through_the_kernel(): void
    {
        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
        $warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
        Product::factory()->create(['sku' => 'KOPI-01', 'track_batch' => true, 'track_expiry' => true, 'shelf_life_days' => 365]);
        Product::factory()->create(['sku' => 'TEH-01']);

        $path = $this->sheet($this->openingStock(), [
            ['WH-01', 'KOPI-01', '480', '2500', 'B-2608', '2026-08-01', ''],
            ['WH-01', 'TEH-01', '100', '3000', '', '', ''],
        ]);

        $written = $this->openingStock()->import($path);

        $this->assertSame(2, $written);
        $this->assertDatabaseCount('stock_ledger', 2);
        // One document, so one claim and one reference across both lines.
        $this->assertDatabaseCount('document_postings', 1);
        $this->assertSame(1, StockLedger::query()->distinct()->count('ref_id'));
        // Cost and journal came with it, which a bulk insert would have skipped.
        $this->assertSame('2500.0000', (string) ProductCost::whereHas(
            'product', fn ($q) => $q->where('sku', 'KOPI-01'))->firstOrFail()->average_cost);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_opening_stock_faces_equity_not_an_expense(): void
    {
        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
        Warehouse::factory()->create(['code' => 'WH-01']);
        Product::factory()->create(['sku' => 'TEH-01']);

        $path = $this->sheet($this->openingStock(), [['WH-01', 'TEH-01', '100', '3000', '', '', '']]);
        $this->openingStock()->import($path);

        $lines = JournalLine::with('account')->get();
        $sides = $lines->mapWithKeys(fn ($line) => [
            $line->account->code => $line->debit->isPositive() ? 'debit' : 'credit',
        ])->all();

        // 3102 is Opening Balance Equity. Booking this to a variance expense
        // would put the whole opening inventory through month one's P&L.
        $this->assertEquals(['1103' => 'debit', '3102' => 'credit'], $sides);
    }

    public function test_a_short_dated_opening_batch_stops_the_whole_import(): void
    {
        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
        Warehouse::factory()->create(['code' => 'WH-01']);
        Product::factory()->create([
            'sku' => 'KOPI-01', 'track_batch' => true, 'track_expiry' => true,
            'shelf_life_days' => 100, 'min_life_on_receipt_pct' => '70.00',
        ]);

        $path = $this->sheet($this->openingStock(), [
            ['WH-01', 'KOPI-01', '480', '2500', 'B-OLD', '', '2026-10-25'],
        ]);

        try {
            $this->openingStock()->import($path);
            $this->fail('Expected the shelf-life rule to apply to opening stock too.');
        } catch (ImportHasProblems $exception) {
            $this->assertStringContainsString('days required', $exception->problems[0]->message);
            $this->assertDatabaseCount('stock_ledger', 0);
            // No claim left behind, so a corrected file imports cleanly.
            $this->assertDatabaseCount('document_postings', 0);
        }
    }

    #[Group('slow')]
    public function test_three_thousand_rows_import_inside_the_two_minute_target(): void
    {
        // F-02 names this as an acceptance criterion, so it is measured rather
        // than assumed. Opening stock is the heavy one: every line goes
        // through the posting kernel, taking the cost and balance locks and
        // writing a journal.
        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
        Warehouse::factory()->create(['code' => 'WH-01']);

        // Bulk-insert the fixture: 3,000 factory models is heavier than the
        // thing being measured and tells us nothing about the import.
        $rows = [];
        $products = [];
        foreach (range(1, 3000) as $n) {
            $sku = sprintf('SKU-%05d', $n);
            $products[] = [
                'id' => (string) Str::uuid7(), 'sku' => $sku, 'name' => $sku,
                'base_uom' => 'PCS', 'track_batch' => false, 'track_expiry' => false,
                'issue_method' => 'fefo', 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ];
            $rows[] = ['WH-01', $sku, '100', '2500', '', '', ''];
        }

        foreach (array_chunk($products, 500) as $chunk) {
            DB::table('products')->insert($chunk);
        }

        $path = $this->sheet($this->openingStock(), $rows);

        $started = microtime(true);
        $written = $this->openingStock()->import($path);
        $seconds = microtime(true) - $started;

        $this->assertSame(3000, $written);
        $this->assertLessThan(120, $seconds, sprintf('3,000 rows took %.1fs; F-02 allows 120s.', $seconds));
        fwrite(STDERR, sprintf("\n  3,000 opening stock rows: %.1fs\n", $seconds));
    }

    public function test_the_import_page_checks_before_it_commits(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', 'Administrator')->value('id'));
        $this->actingAs($user);

        $path = $this->sheet(app(ProductImporter::class), [
            ['KOPI-01', 'Kopi Susu', 'PCS', 'NO-SUCH-BRAND', '', 'N', 'N', '', '', '', '', '', ''],
        ]);

        $page = Livewire::test(ImportData::class)
            ->fillForm([
                'type' => 'products',
                'file' => UploadedFile::fake()->createWithContent('products.xlsx', file_get_contents($path)),
            ])
            ->call('check');

        // Checking reports the problem and saves nothing.
        $page->assertSee('no brand has the code');
        $this->assertDatabaseCount('products', 0);

        Brand::create(['code' => 'NO-SUCH-BRAND', 'name' => 'Now It Exists']);

        $page->call('check')->call('commit');

        $this->assertDatabaseHas('products', ['sku' => 'KOPI-01']);
    }

    private function openingStock(): OpeningStockImporter
    {
        return new OpeningStockImporter(
            app(StockPosting::class),
            app(ResolveStockBatch::class),
            new CarbonImmutable('2026-08-26'),
        );
    }

    /** @param  list<list<string>>  $rows */
    private function sheet(SpreadsheetImporter $importer, array $rows): string
    {
        $path = $this->tempFile();
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_values($importer->columns())));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        $this->files[] = $path;

        return $path;
    }
}
