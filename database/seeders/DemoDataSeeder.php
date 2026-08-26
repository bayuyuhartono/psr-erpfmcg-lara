<?php

namespace Database\Seeders;

use App\Domain\Inventory\Actions\ResolveStockBatch;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseLocation;
use App\Domain\Masters\Actions\AddProductUom;
use App\Domain\Masters\Models\Brand;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Category;
use App\Domain\Masters\Models\CustomerTier;
use App\Domain\Masters\Models\DiscountRule;
use App\Domain\Masters\Models\PriceList;
use App\Domain\Masters\Models\PriceListItem;
use App\Domain\Masters\Models\Product;
use App\Domain\Masters\Models\Salesperson;
use App\Domain\Masters\Models\Territory;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One plausible distributor, so the screens have something to say.
 *
 * Deliberately **not** in `DatabaseSeeder` — a real installation seeds its
 * configuration and then imports the client's own masters. This is for demos
 * and for looking at the thing while building it:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Idempotent, so running it twice is safe.
 */
final class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Posting opening stock needs a chart of accounts, its mappings and a
        // journal sequence. Calling them rather than failing on a missing
        // mapping: they are idempotent, and `db:seed --class=DemoDataSeeder`
        // on a fresh database should simply work.
        $this->call([
            CompanySettingsSeeder::class,
            ChartOfAccountsSeeder::class,
            JournalMappingsSeeder::class,
            NumberSequencesSeeder::class,
        ]);

        $warehouse = $this->warehouses();
        [$wholesale, $retail] = $this->pricingTiers();
        $products = $this->catalogue();
        $this->prices($products, $wholesale, $retail);
        $this->customers($wholesale, $retail);
        $this->discounts();
        $this->openingStock($warehouse, $products);

        $this->command?->info(sprintf(
            'Demo data: %d products, %d customers, %d warehouse(s), %s units on hand. Order entry is ready.',
            count($products),
            BusinessPartner::query()->customers()->count(),
            Warehouse::count(),
            number_format((float) DB::table('stock_balances')->sum('qty_base')),
        ));
    }

    private function warehouses(): Warehouse
    {
        $main = Warehouse::firstOrCreate(['code' => 'WH-JKT'], [
            'name' => 'Gudang Jakarta', 'warehouse_type' => 'normal', 'active' => true,
        ]);

        // Damaged goods have somewhere to go, so a non-resellable sales return
        // can actually be posted.
        Warehouse::firstOrCreate(['code' => 'WH-RUSAK'], [
            'name' => 'Gudang Barang Rusak', 'warehouse_type' => 'damaged', 'active' => true,
        ]);

        // A rack walk, so picking lists sort into a sensible order.
        foreach ([['A-01', 10], ['A-02', 20], ['A-03', 30], ['B-01', 40], ['B-02', 50], ['C-01', 60]] as [$code, $sequence]) {
            WarehouseLocation::firstOrCreate(
                ['warehouse_id' => $main->id, 'code' => $code],
                ['pick_sequence' => $sequence],
            );
        }

        return $main;
    }

    /** @return array{PriceList, PriceList} */
    private function pricingTiers(): array
    {
        $wholesale = PriceList::firstOrCreate(['name' => 'Grosir'], ['active' => true]);
        $retail = PriceList::firstOrCreate(['name' => 'Eceran'], ['active' => true]);

        CustomerTier::firstOrCreate(['name' => 'Grosir'], ['price_list_id' => $wholesale->id]);
        CustomerTier::firstOrCreate(['name' => 'Eceran'], ['price_list_id' => $retail->id]);

        return [$wholesale, $retail];
    }

    /** @return list<array{Product, int, string}> product, base price, selling unit */
    private function catalogue(): array
    {
        $principal = BusinessPartner::firstOrCreate(['code' => 'P-001'], [
            'name' => 'PT Sinar Makmur Abadi', 'is_principal' => true, 'is_supplier' => true, 'active' => true,
        ]);
        BusinessPartner::firstOrCreate(['code' => 'P-002'], [
            'name' => 'PT Wahana Boga Nusantara', 'is_principal' => true, 'is_supplier' => true, 'active' => true,
        ]);

        $beverages = Category::firstOrCreate(['name' => 'Minuman']);
        $coffee = Category::firstOrCreate(['name' => 'Kopi', 'parent_id' => $beverages->id]);
        $snacks = Category::firstOrCreate(['name' => 'Makanan Ringan']);
        $household = Category::firstOrCreate(['name' => 'Kebutuhan Rumah']);

        $kopi = Brand::firstOrCreate(['code' => 'BR-KOPI'], ['name' => 'Kopi Nusantara', 'principal_id' => $principal->id, 'active' => true]);
        $sari = Brand::firstOrCreate(['code' => 'BR-SARI'], ['name' => 'Sari Rasa', 'principal_id' => $principal->id, 'active' => true]);
        $bersih = Brand::firstOrCreate(['code' => 'BR-BERSIH'], ['name' => 'Bersih Sejahtera', 'active' => true]);

        // sku, name, category, brand, base price, sell unit, per carton, perishable
        $catalogue = [
            ['KOPI-250', 'Kopi Bubuk Nusantara 250g', $coffee, $kopi, 18_000, 'DUS', 24, 540, '66.00'],
            ['KOPI-SCH', 'Kopi Sachet Nusantara 20g', $coffee, $kopi, 1_500, 'DUS', 120, 365, '66.00'],
            ['SUSU-200', 'Susu UHT Sari Rasa 200ml', $beverages, $sari, 5_500, 'DUS', 24, 180, '66.00'],
            // No receipt threshold on these two, which is how stock ends up
            // ageing on a shelf and turning up in the expiry report.
            ['TEH-350', 'Teh Kotak Sari Rasa 350ml', $beverages, $sari, 4_000, 'DUS', 24, 270, null],
            ['ROTI-400', 'Roti Tawar Sari Rasa 400g', $snacks, $sari, 14_000, 'DUS', 10, 90, null],
            ['BISK-120', 'Biskuit Kelapa Sari Rasa 120g', $snacks, $sari, 8_500, 'DUS', 40, 365, '66.00'],
            ['KRIP-80', 'Keripik Singkong Pedas 80g', $snacks, $sari, 6_000, 'DUS', 50, 180, '66.00'],
            ['SABUN-90', 'Sabun Mandi Bersih 90g', $household, $bersih, 3_500, 'DUS', 72, null, null],
            ['DETER-800', 'Deterjen Bubuk Bersih 800g', $household, $bersih, 21_000, 'DUS', 12, null, null],
            ['MINY-1L', 'Minyak Goreng Bersih 1L', $household, $bersih, 17_500, 'DUS', 12, 540, '66.00'],
            ['GULA-1KG', 'Gula Pasir Premium 1kg', $household, null, 15_000, 'SAK', 25, null, null],
        ];

        $products = [];

        foreach ($catalogue as [$sku, $name, $category, $brand, $price, $unit, $perCarton, $shelfLife, $minLifePct]) {
            $product = Product::firstOrCreate(['sku' => $sku], [
                'name' => $name,
                'category_id' => $category->id,
                'brand_id' => $brand?->id,
                'base_uom' => 'PCS',
                'track_batch' => $shelfLife !== null,
                'track_expiry' => $shelfLife !== null,
                'issue_method' => 'fefo',
                'shelf_life_days' => $shelfLife,
                // Where set, goods must arrive with two thirds of their
                // life left, and are refused outright below it.
                'min_life_on_receipt_pct' => $minLifePct,
                'min_life_on_ship_days' => $shelfLife === null ? null : 30,
                'vat_percent' => '11.00',
                'reorder_point' => 200,
                'active' => true,
            ]);

            app(AddProductUom::class)($product->fresh(), $unit, (string) $perCarton, defaultForSale: true, defaultForPurchase: true);

            $products[] = [$product->fresh(), $price, $unit];
        }

        return $products;
    }

    /** @param  list<array{Product, int, string}>  $products */
    private function prices(array $products, PriceList $wholesale, PriceList $retail): void
    {
        foreach ($products as [$product, $price, $unit]) {
            $perCarton = (int) (string) $product->productUoms->firstWhere('uom', $unit)->qty_in_base;

            foreach ([[$wholesale, 0.88], [$retail, 1.0]] as [$list, $factor]) {
                // Priced per piece and per carton, because outlets buy both.
                PriceListItem::firstOrCreate(
                    ['price_list_id' => $list->id, 'product_id' => $product->id, 'uom' => 'PCS', 'min_qty' => '0'],
                    ['unit_price' => round($price * $factor, 2)],
                );

                PriceListItem::firstOrCreate(
                    ['price_list_id' => $list->id, 'product_id' => $product->id, 'uom' => $unit, 'min_qty' => '0'],
                    ['unit_price' => round($price * $factor * $perCarton, 2)],
                );

                // A volume band: ten cartons or more is cheaper.
                PriceListItem::firstOrCreate(
                    ['price_list_id' => $list->id, 'product_id' => $product->id, 'uom' => $unit, 'min_qty' => '10'],
                    ['unit_price' => round($price * $factor * $perCarton * 0.96, 2)],
                );
            }
        }
    }

    private function customers(PriceList $wholesale, PriceList $retail): void
    {
        $jakarta = Territory::firstOrCreate(['name' => 'Jakarta Timur']);
        $bekasi = Territory::firstOrCreate(['name' => 'Bekasi']);

        $budi = Salesperson::firstOrCreate(['code' => 'S-01'], ['name' => 'Budi Santoso', 'territory_id' => $jakarta->id, 'sales_type' => 'taking_order', 'active' => true]);
        $rina = Salesperson::firstOrCreate(['code' => 'S-02'], ['name' => 'Rina Wijaya', 'territory_id' => $bekasi->id, 'sales_type' => 'canvas', 'active' => true]);

        $grosir = CustomerTier::where('name', 'Grosir')->first();
        $eceran = CustomerTier::where('name', 'Eceran')->first();

        // name, tier, salesperson, territory, credit limit, terms, exchange days, payment days
        $outlets = [
            ['Toko Berkah Jaya', $grosir, $budi, $jakarta, 50_000_000, 30, [1, 15], [10, 25]],
            ['Toko Sumber Rezeki', $grosir, $budi, $jakarta, 35_000_000, 30, [1, 15], [10, 25]],
            ['Warung Bu Sari', $eceran, $budi, $jakarta, 5_000_000, 14, [7], [14]],
            ['Toko Mitra Sejahtera', $grosir, $rina, $bekasi, 40_000_000, 30, [5, 20], [15, 30]],
            ['Warung Pak Karno', $eceran, $rina, $bekasi, 3_000_000, 7, [7], [14]],
            ['Toko Cahaya Abadi', $grosir, $rina, $bekasi, 25_000_000, 30, [5, 20], [15, 30]],
            ['Minimarket Sinar Pagi', $eceran, $budi, $jakarta, 15_000_000, 21, [1], [21]],
            ['Toko Harapan Baru', $grosir, $rina, $bekasi, 30_000_000, 30, [5, 20], [15, 30]],
        ];

        foreach ($outlets as $index => [$name, $tier, $rep, $territory, $limit, $terms, $exchangeDays, $paymentDays]) {
            BusinessPartner::firstOrCreate(['code' => sprintf('C-%05d', $index + 1)], [
                'name' => $name,
                'is_customer' => true,
                'tier_id' => $tier?->id,
                'salesperson_id' => $rep->id,
                'territory_id' => $territory->id,
                'credit_limit' => $limit,
                'payment_terms_days' => $terms,
                'invoice_exchange_days' => $exchangeDays,
                'payment_days' => $paymentDays,
                'phone' => '021-'.random_int(5000000, 8999999),
                'active' => true,
            ]);
        }

        // A supplier who is not the principal, for the purchasing screens.
        BusinessPartner::firstOrCreate(['code' => 'S-901'], [
            'name' => 'CV Aneka Pangan', 'is_supplier' => true, 'active' => true,
        ]);
    }

    /**
     * Stock on the shelf, through the posting kernel like everything else.
     *
     * Batches are given a spread of expiry dates on purpose: it gives FEFO
     * something to choose between at picking, and the expiry report something
     * to put in each band.
     *
     * @param  list<array{Product, int, string}>  $products
     */
    private function openingStock(Warehouse $warehouse, array $products): void
    {
        if (DB::table('stock_ledger')->where('ref_type', 'opening_balance')->exists()) {
            return;
        }

        $countedOn = CarbonImmutable::now()->startOfDay();
        $resolveBatch = app(ResolveStockBatch::class);
        $movements = [];
        $reference = (string) Str::uuid7();

        foreach ($products as $index => [$product, $price, $unit]) {
            $cost = round($price * 0.72, 2);   // bought at roughly a 28% margin

            // Perishables arrive as two batches, one closer to its date than
            // the other.
            if (! $product->track_batch) {
                $batches = [[null, null]];
            } elseif ($product->min_life_on_receipt_pct === null) {
                // Nothing stops these arriving old, so the demo has some that
                // did — one batch inside 30 days of its date, one inside 90.
                $life = (int) $product->shelf_life_days;
                $batches = [
                    ['B-LAMA-'.$product->sku, $countedOn->subDays((int) ($life * 0.88))],
                    ['B-BARU-'.$product->sku, $countedOn->subDays((int) ($life * 0.45))],
                ];
            } else {
                $batches = [
                    ['B-'.$countedOn->format('ym').'-A', $countedOn->subDays(30 + $index * 5)],
                    ['B-'.$countedOn->format('ym').'-B', $countedOn->subDays(5 + $index)],
                ];
            }

            foreach ($batches as $position => [$number, $producedOn]) {
                $batch = $resolveBatch($product, $number, $countedOn, $producedOn);

                $movements[] = new StockMovement(
                    warehouseId: $warehouse->getKey(),
                    productId: $product->getKey(),
                    quantity: (string) (($position === 0 ? 600 : 400) + $index * 40),
                    unitCost: (string) $cost,
                    referenceType: 'opening_balance',
                    referenceId: $reference,
                    transactionDate: $countedOn,
                    batchId: $batch?->getKey(),
                    description: 'Opening stock',
                );
            }
        }

        app(StockPosting::class)->postDocument($movements);
    }

    private function discounts(): void
    {
        $grosir = CustomerTier::where('name', 'Grosir')->first();

        DiscountRule::firstOrCreate(['name' => 'Potongan grosir'], [
            'scope' => 'all', 'target' => 'tier', 'target_id' => $grosir?->id,
            'percent' => '2.50', 'sequence' => 1,
        ]);

        DiscountRule::firstOrCreate(['name' => 'Promo kopi'], [
            'scope' => 'brand', 'scope_id' => Brand::where('code', 'BR-KOPI')->value('id'),
            'target' => 'all', 'percent' => '3.00', 'sequence' => 2,
            'min_qty' => '5',
        ]);
    }
}
