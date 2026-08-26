<?php

namespace App\Domain\Masters\Imports;

use App\Domain\Masters\Actions\AddProductUom;
use App\Domain\Masters\Models\Brand;
use App\Domain\Masters\Models\Category;
use App\Domain\Masters\Models\Product;
use App\Support\Import\ImportRow;
use App\Support\Import\RowProblem;
use App\Support\Import\SpreadsheetImporter;

/**
 * Products, with an optional selling unit (F-02).
 *
 * The columns match the product form, because that is the only definition of
 * a product anyone in the business shares. Shelf-life coherence, the locked
 * base unit and the one-default-per-direction rule are not restated here —
 * they run for real when the rows are written, and a preview reports whatever
 * they refuse.
 */
final class ProductImporter extends SpreadsheetImporter
{
    public function __construct(private readonly AddProductUom $addUnit) {}

    public function columns(): array
    {
        return [
            'sku' => 'SKU',
            'name' => 'Name',
            'base_uom' => 'Base unit',
            'brand_code' => 'Brand code',
            'category' => 'Category',
            'track_batch' => 'Track batches (Y/N)',
            'track_expiry' => 'Track expiry (Y/N)',
            'shelf_life_days' => 'Shelf life (days)',
            'min_life_on_receipt_pct' => 'Min life on receipt (%)',
            'min_life_on_ship_days' => 'Min life on shipment (days)',
            'vat_percent' => 'VAT (%)',
            'sell_uom' => 'Selling unit',
            'sell_qty_in_base' => 'Selling unit in base units',
        ];
    }

    public function example(): array
    {
        return [
            'sku' => 'KOPI-01', 'name' => 'Kopi Susu 200ml', 'base_uom' => 'PCS',
            'brand_code' => 'BR-KOPI', 'category' => 'Beverages',
            'track_batch' => 'Y', 'track_expiry' => 'Y',
            'shelf_life_days' => '365', 'min_life_on_receipt_pct' => '70',
            'min_life_on_ship_days' => '30', 'vat_percent' => '11',
            'sell_uom' => 'CARTON', 'sell_qty_in_base' => '24',
        ];
    }

    protected function check(ImportRow $row): array
    {
        $problems = [];

        foreach (['sku', 'name', 'base_uom'] as $required) {
            if ($row->get($required) === null) {
                $problems[] = new RowProblem($row->line, $this->columns()[$required], 'is required');
            }
        }

        foreach (['shelf_life_days', 'min_life_on_receipt_pct', 'min_life_on_ship_days', 'vat_percent', 'sell_qty_in_base'] as $numeric) {
            $value = $row->get($numeric);
            if ($value !== null && ! is_numeric($value)) {
                $problems[] = new RowProblem($row->line, $this->columns()[$numeric], sprintf('"%s" is not a number', $value));
            }
        }

        if (($code = $row->get('brand_code')) !== null && ! Brand::where('code', $code)->exists()) {
            $problems[] = new RowProblem($row->line, 'Brand code', sprintf('no brand has the code "%s"', $code));
        }

        if ($row->get('sell_uom') !== null && $row->get('sell_qty_in_base') === null) {
            $problems[] = new RowProblem($row->line, 'Selling unit in base units', 'is required when a selling unit is given');
        }

        return $problems;
    }

    protected function persist(array $rows): int
    {
        return $this->eachRow($rows, function (ImportRow $row): void {
            $product = Product::updateOrCreate(['sku' => $row->get('sku')], [
                'name' => $row->get('name'),
                'base_uom' => $row->get('base_uom'),
                'brand_id' => Brand::where('code', $row->get('brand_code'))->value('id'),
                'category_id' => $this->categoryId($row->get('category')),
                'track_batch' => $row->boolean('track_batch'),
                'track_expiry' => $row->boolean('track_expiry'),
                'issue_method' => 'fefo',
                'shelf_life_days' => $row->get('shelf_life_days'),
                'min_life_on_receipt_pct' => $row->get('min_life_on_receipt_pct'),
                'min_life_on_ship_days' => $row->get('min_life_on_ship_days'),
                'vat_percent' => $row->get('vat_percent'),
                'active' => true,
            ]);

            if ($row->get('sell_uom') !== null) {
                ($this->addUnit)($product, $row->get('sell_uom'), $row->get('sell_qty_in_base'), defaultForSale: true);
            }
        });
    }

    private function categoryId(?string $name): ?string
    {
        return $name === null ? null : Category::firstOrCreate(['name' => $name])->getKey();
    }
}
