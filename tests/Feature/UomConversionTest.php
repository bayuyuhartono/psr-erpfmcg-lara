<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Actions\AddProductUom;
use App\Domain\Masters\Models\Product;
use App\Domain\Masters\Models\ProductUom;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use App\Support\Uom\Exceptions\BaseUnitLocked;
use App\Support\Uom\Exceptions\InvalidConversion;
use App\Support\Uom\Exceptions\UnknownUnit;
use App\Support\Uom\UomConverter;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UomConversionTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->create(['sku' => 'BISKUIT-01', 'base_uom' => 'PCS']);
    }

    public function test_nested_units_each_convert_straight_to_base(): void
    {
        // Carton holds 12 packs, pack holds 12 pieces. Each unit declares its
        // own factor in base units, so nothing has to walk a chain.
        $this->unit('PACK', '12');
        $this->unit('CARTON', '144');

        $converter = app(UomConverter::class);

        $this->assertSame('144.0000', (string) $converter->toBase($this->product, 'CARTON', '1'));
        $this->assertSame('36.0000', (string) $converter->toBase($this->product, 'PACK', '3'));
        $this->assertSame('7.0000', (string) $converter->toBase($this->product, 'PCS', '7'));
    }

    public function test_a_line_snapshot_carries_the_factor_it_was_priced_at(): void
    {
        $this->unit('CARTON', '144');

        $snapshot = app(UomConverter::class)->snapshot($this->product, 'CARTON', '2');

        $this->assertSame([
            'uom' => 'CARTON',
            'qty' => '2.0000',
            'qty_in_base' => '144.0000',
            'qty_base' => '288.0000',
        ], array_map('strval', $snapshot->toLineAttributes()));

        // The carton is corrected to 120 later. The line already written keeps
        // meaning 288 pieces, because it copied the factor rather than pointing
        // at it.
        $this->unit('CARTON', '120');
        $this->assertSame('288.0000', (string) $snapshot->quantityBase);
        $this->assertSame('240.0000', (string) app(UomConverter::class)->toBase($this->product->fresh(), 'CARTON', '2'));
    }

    public function test_an_unknown_unit_is_refused_rather_than_assumed_to_be_base(): void
    {
        $this->expectException(UnknownUnit::class);
        $this->expectExceptionMessageMatches('/BISKUIT-01 has no unit "DUS"/');

        app(UomConverter::class)->toBase($this->product, 'DUS', '1');
    }

    public function test_a_unit_must_contain_a_positive_number_of_base_units(): void
    {
        $this->expectException(InvalidConversion::class);

        $this->unit('CARTON', '0');
    }

    public function test_the_base_unit_contains_exactly_one_of_itself(): void
    {
        $this->expectException(InvalidConversion::class);
        $this->expectExceptionMessageMatches('/exactly one of itself/');

        $this->unit('PCS', '12');
    }

    public function test_only_one_unit_is_the_default_for_each_direction(): void
    {
        $this->unit('CARTON', '144', defaultForSale: true);
        $this->unit('PACK', '12', defaultForSale: true);

        $defaults = ProductUom::where('product_id', $this->product->id)->where('default_for_sale', true)->pluck('uom');

        $this->assertSame(['PACK'], $defaults->all());
    }

    public function test_the_base_unit_locks_once_the_product_has_moved(): void
    {
        // Free to correct while the product has no history.
        $this->product->update(['base_uom' => 'GRAM']);
        $this->assertSame('GRAM', $this->product->fresh()->base_uom);

        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
        app(StockPosting::class)->postDocument([new StockMovement(
            Warehouse::factory()->create()->id, $this->product->id, '500', '10', 'goods_receipt',
            (string) Str::uuid7(), new DateTimeImmutable('2026-08-26'),
        )]);

        try {
            $this->product->update(['base_uom' => 'CARTON']);
            $this->fail('Expected the base unit to be locked.');
        } catch (BaseUnitLocked) {
            // 500 grams must not quietly become 500 cartons.
            $this->assertSame('GRAM', $this->product->fresh()->base_uom);
        }
    }

    private function unit(string $uom, string $quantityInBase, bool $defaultForSale = false): void
    {
        app(AddProductUom::class)($this->product, $uom, $quantityInBase, defaultForSale: $defaultForSale);
        $this->product->refresh();
    }
}
