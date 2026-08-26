<?php

namespace Tests\Feature;

use App\Domain\Masters\Models\Product;
use App\Domain\Masters\Models\ProductUom;
use App\Domain\Masters\Services\ProductSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

final class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_matches_code_name_and_barcode(): void
    {
        $bySku = Product::factory()->create(['sku' => 'KOPI-01', 'name' => 'Kopi Susu 200ml']);
        $byName = Product::factory()->create(['sku' => 'X-99', 'name' => 'Teh Kotak Kopi Blend']);
        $byBarcode = Product::factory()->create(['sku' => 'Y-11', 'name' => 'Biskuit Kelapa']);
        ProductUom::create([
            'product_id' => $byBarcode->id, 'uom' => 'CARTON', 'qty_in_base' => '24', 'barcode' => '8991234567890',
        ]);

        $search = app(ProductSearch::class);

        $this->assertTrue($search('KOPI')->contains($bySku));
        $this->assertTrue($search('KOPI')->contains($byName));
        $this->assertTrue($search('8991234567890')->contains($byBarcode));
    }

    public function test_the_obvious_answer_comes_first(): void
    {
        Product::factory()->create(['sku' => 'KOPI-011', 'name' => 'Aaa Kopi']);
        $exact = Product::factory()->create(['sku' => 'KOPI-01', 'name' => 'Zzz Kopi']);

        // Typed the exact code, so that is the one wanted — not the one that
        // happens to sort first alphabetically.
        $this->assertSame($exact->id, app(ProductSearch::class)('KOPI-01')->first()->id);
    }

    public function test_inactive_products_are_not_offered(): void
    {
        Product::factory()->create(['sku' => 'OLD-01', 'name' => 'Discontinued', 'active' => false]);

        $this->assertCount(0, app(ProductSearch::class)('OLD'));
    }

    #[Group('slow')]
    public function test_search_stays_under_300ms_across_five_thousand_skus(): void
    {
        // F-31 names 300 ms across 5,000 SKUs as an acceptance criterion, so
        // it is measured rather than assumed.
        $rows = [];
        foreach (range(1, 5000) as $n) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'sku' => sprintf('SKU-%05d', $n),
                'name' => fake()->words(3, true).' '.$n,
                'base_uom' => 'PCS', 'track_batch' => false, 'track_expiry' => false,
                'issue_method' => 'fefo', 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('products')->insert($chunk);
        }

        $search = app(ProductSearch::class);
        $search('warm-up');

        $slowest = 0.0;
        foreach (['SKU-04', 'SKU-04321', 'kopi', 'a'] as $term) {
            $started = microtime(true);
            $search($term);
            $slowest = max($slowest, (microtime(true) - $started) * 1000);
        }

        $this->assertLessThan(300, $slowest, sprintf('Slowest search took %.0f ms; F-31 allows 300 ms.', $slowest));
        fwrite(STDERR, sprintf("\n  Product search across 5,000 SKUs: %.0f ms worst case\n", $slowest));
    }
}
