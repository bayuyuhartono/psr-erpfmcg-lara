<?php

namespace Tests\Feature;

use App\Domain\Finance\Actions\AssembleInvoiceExchange;
use App\Domain\Finance\Services\ReceivablesAging;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\Brand;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\CustomerTier;
use App\Domain\Masters\Models\PriceList;
use App\Domain\Masters\Models\PriceListItem;
use App\Domain\Masters\Models\PrintTemplate;
use App\Domain\Masters\Models\Product;
use App\Domain\Masters\Models\Salesperson;
use App\Domain\Purchasing\Actions\DraftGoodsReceipt;
use App\Domain\Purchasing\Actions\PostGoodsReceipt;
use App\Domain\Sales\Actions\DraftSalesOrder;
use App\Domain\Sales\Actions\InvoiceDelivery;
use App\Domain\Sales\Actions\PlanDelivery;
use App\Domain\Sales\Actions\RecordProofOfDelivery;
use App\Domain\Sales\Actions\ShipDelivery;
use App\Domain\Sales\Actions\SubmitSalesOrder;
use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Services\SalesAnalysis;
use App\Support\Printing\DocumentPrinter;
use App\Support\Printing\Exceptions\NoTemplate;
use App\Support\Printing\TemplateRenderer;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\PrintTemplatesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

final class PrintingAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private BusinessPartner $customer;

    private BusinessPartner $supplier;

    private Warehouse $warehouse;

    private Product $product;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class, JournalMappingsSeeder::class,
            NumberSequencesSeeder::class, PrintTemplatesSeeder::class,
        ]);
        $this->today = new CarbonImmutable('2026-08-26');
        $this->warehouse = Warehouse::factory()->create();
        $this->supplier = BusinessPartner::factory()->create(['is_supplier' => true]);

        $brand = Brand::create(['code' => 'BR-KOPI', 'name' => 'Kopi Nusantara']);
        $this->product = Product::factory()->create([
            'sku' => 'KOPI-01', 'name' => 'Kopi Susu 200ml',
            'base_uom' => 'PCS', 'brand_id' => $brand->id, 'vat_percent' => '11.00',
        ]);

        $list = PriceList::create(['name' => 'Wholesale', 'active' => true]);
        PriceListItem::create([
            'price_list_id' => $list->id, 'product_id' => $this->product->id,
            'uom' => 'PCS', 'min_qty' => '0', 'unit_price' => '5000',
        ]);
        $tier = CustomerTier::create(['name' => 'Wholesale', 'price_list_id' => $list->id]);
        $rep = Salesperson::create(['code' => 'S-01', 'name' => 'Budi']);
        $this->customer = BusinessPartner::factory()->create([
            'code' => 'C-00123', 'name' => 'Toko Berkah',
            'tier_id' => $tier->id, 'salesperson_id' => $rep->id,
            'credit_limit' => '50000000', 'payment_terms_days' => 30,
            'invoice_exchange_days' => [26],
        ]);
    }

    // 8.2 — layout is data

    public function test_a_layout_change_needs_no_code_change(): void
    {
        $invoice = $this->sell('100');

        $before = app(DocumentPrinter::class)->print($invoice, 'dotmatrix')['body'];
        $this->assertStringContainsString('F A K T U R', $before);

        PrintTemplate::query()
            ->where(['document_type' => 'sales_invoice', 'format' => 'dotmatrix'])
            ->update(['body' => "TAGIHAN {{ invoice.number }}\nJUMLAH {{ invoice.total | money }}", 'copies' => 1]);

        $after = app(DocumentPrinter::class)->print($invoice, 'dotmatrix')['body'];

        $this->assertStringContainsString('TAGIHAN '.$invoice->document_number, $after);
        $this->assertStringNotContainsString('F A K T U R', $after);
    }

    public function test_a_template_cannot_execute_code(): void
    {
        // Layouts are edited by whoever installs the system. A Blade template
        // from the database would be arbitrary PHP in their hands.
        $rendered = app(TemplateRenderer::class)->render(
            '{{ invoice.number }} <?php echo "run"; ?> {{ php_sapi_name() }}',
            ['invoice' => ['number' => 'INV-1']],
        );

        $this->assertStringContainsString('INV-1', $rendered);
        $this->assertStringContainsString('<?php echo "run"; ?>', $rendered);
        $this->assertStringNotContainsString('cli', $rendered);
    }

    public function test_columns_hold_their_position_on_continuous_form(): void
    {
        // A column one character out puts the whole run out of alignment with
        // pre-printed stationery.
        $rendered = app(TemplateRenderer::class)->render(
            "{{# lines }}{{ sku | pad:10 }}|{{ qty | rpad:8 }}|\n{{/ lines }}",
            ['lines' => [
                ['sku' => 'KOPI-01', 'qty' => '5'],
                ['sku' => 'A-VERY-LONG-SKU-INDEED', 'qty' => '1234'],
            ]],
        );

        foreach (explode("\n", trim($rendered)) as $line) {
            $this->assertSame(10, mb_strpos($line, '|'), "Column drifted: {$line}");
            $this->assertSame(19, mb_strrpos($line, '|'), "Column drifted: {$line}");
        }
    }

    public function test_the_dot_matrix_invoice_carries_its_copies(): void
    {
        $invoice = $this->sell('100');

        $printed = app(DocumentPrinter::class)->print($invoice, 'dotmatrix');

        $this->assertSame(3, $printed['copies']);
        $this->assertSame(80, $printed['width']);
        // Continuous form is one run separated by form feeds, not three jobs.
        $this->assertSame(2, substr_count($printed['body'], "\f"));
        $this->assertStringContainsString('ORIGINAL', $printed['body']);
        $this->assertStringContainsString('FILE COPY', $printed['body']);
    }

    public function test_no_line_runs_past_the_paper(): void
    {
        $invoice = $this->sell('100');
        $printed = app(DocumentPrinter::class)->print($invoice, 'dotmatrix');

        foreach (explode("\n", str_replace("\f", "\n", $printed['body'])) as $line) {
            $this->assertLessThanOrEqual(
                $printed['width'],
                mb_strlen(rtrim($line)),
                "Line is wider than the paper: {$line}",
            );
        }
    }

    // 8.3 / 8.4 — the other documents

    public function test_the_a4_invoice_renders_its_lines(): void
    {
        $invoice = $this->sell('100');

        $printed = app(DocumentPrinter::class)->print($invoice, 'a4');

        $this->assertStringContainsString('<article class="invoice">', $printed['body']);
        $this->assertStringContainsString('Kopi Susu 200ml', $printed['body']);
        $this->assertStringContainsString('500.000,00', $printed['body']);
        $this->assertSame(1, $printed['copies']);
    }

    public function test_the_delivery_note_and_exchange_receipt_print(): void
    {
        $invoice = $this->sell('100');

        $note = app(DocumentPrinter::class)->print(Delivery::firstOrFail(), 'dotmatrix')['body'];
        $this->assertStringContainsString('S U R A T   J A L A N', $note);
        $this->assertStringContainsString('Toko Berkah', $note);

        $exchange = app(AssembleInvoiceExchange::class)($this->customer->fresh(), $this->today);
        $receipt = app(DocumentPrinter::class)->print($exchange, 'dotmatrix')['body'];

        $this->assertStringContainsString('T A N D A   T E R I M A', $receipt);
        $this->assertStringContainsString($invoice->document_number, $receipt);
        // Signable: it is handed over against a name.
        $this->assertStringContainsString('Diterima oleh,', $receipt);
    }

    public function test_a_missing_layout_says_what_to_do(): void
    {
        PrintTemplate::query()->where('document_type', 'delivery')->delete();

        $this->sell('10');

        $this->expectException(NoTemplate::class);
        $this->expectExceptionMessageMatches('/configuration, not code/');

        app(DocumentPrinter::class)->print(Delivery::firstOrFail(), 'dotmatrix');
    }

    // 8.6 — reports

    public function test_sales_break_down_by_product_brand_customer_and_salesperson(): void
    {
        $this->sell('100');
        $from = new CarbonImmutable('2026-08-01');
        $to = new CarbonImmutable('2026-08-31');
        $analysis = app(SalesAnalysis::class);

        foreach (['product' => 'KOPI-01', 'brand' => 'BR-KOPI', 'customer' => 'C-00123', 'salesperson' => 'S-01'] as $dimension => $code) {
            $rows = $analysis->by($dimension, $from, $to);
            $this->assertSame($code, $rows[0]['code'], "Breaking down by {$dimension}");
            $this->assertSame('500000.00', $rows[0]['revenue']);
        }
    }

    public function test_margin_is_revenue_against_what_the_goods_actually_cost(): void
    {
        $this->sell('100');

        $margin = app(SalesAnalysis::class)->grossMargin(new CarbonImmutable('2026-08-01'), new CarbonImmutable('2026-08-31'));

        // 100 sold at 5,000, bought at 3,000 — the cost the goods left at,
        // not a price list guess.
        $this->assertSame('500000.00', $margin['revenue']);
        $this->assertSame('300000.00', $margin['cost']);
        $this->assertSame('200000.00', $margin['margin']);
        $this->assertSame('40.0', $margin['margin_percent']);
    }

    #[Group('slow')]
    public function test_reports_return_inside_five_seconds_across_a_year(): void
    {
        // The NFR names 5 seconds across a year of data, so it is measured.
        // A year for this profile is on the order of 25,000 invoice lines.
        $this->sell('100');
        $invoiceId = SalesInvoice::value('id');
        $lineTemplate = (array) DB::table('sales_invoice_lines')->first();
        unset($lineTemplate['id']);

        $buffer = [];
        foreach (range(1, 25000) as $n) {
            $buffer[] = ['id' => (string) Str::uuid7()] + $lineTemplate;

            if (count($buffer) === 500) {
                DB::table('sales_invoice_lines')->insert($buffer);
                $buffer = [];
            }
        }

        $from = new CarbonImmutable('2026-01-01');
        $to = new CarbonImmutable('2026-12-31');
        $analysis = app(SalesAnalysis::class);
        $slowest = 0.0;

        foreach (['product', 'brand', 'customer', 'salesperson'] as $dimension) {
            $started = microtime(true);
            $analysis->by($dimension, $from, $to);
            $slowest = max($slowest, microtime(true) - $started);
        }

        $started = microtime(true);
        $analysis->grossMargin($from, $to);
        $slowest = max($slowest, microtime(true) - $started);

        $started = microtime(true);
        app(ReceivablesAging::class)($to);
        $slowest = max($slowest, microtime(true) - $started);

        $this->assertLessThan(5, $slowest, sprintf('Slowest report took %.1fs; the NFR allows 5s.', $slowest));
        fwrite(STDERR, sprintf("\n  Reports across 25,000 invoice lines: %.2fs worst case\n", $slowest));
    }

    private function sell(string $qty): SalesInvoice
    {
        $receipt = app(DraftGoodsReceipt::class)($this->supplier, $this->warehouse->id, [
            ['product' => $this->product, 'qty' => '500', 'unit_cost' => '3000'],
        ], null, $this->today);
        app(PostGoodsReceipt::class)($receipt);

        $order = app(SubmitSalesOrder::class)(app(DraftSalesOrder::class)(
            $this->customer->fresh(), $this->warehouse->id,
            [['product' => $this->product, 'qty' => $qty]], $this->today,
        ));

        $delivery = app(PlanDelivery::class)([$order], $this->today);
        app(ShipDelivery::class)($delivery);
        app(RecordProofOfDelivery::class)($delivery->fresh(), [], 'Ibu Sari', $this->today);

        return app(InvoiceDelivery::class)($delivery->fresh());
    }
}
