<?php

namespace App\Domain\Inventory\Imports;

use App\Domain\Inventory\Actions\ResolveStockBatch;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\Product;
use App\Support\Import\Exceptions\ImportHasProblems;
use App\Support\Import\ImportRow;
use App\Support\Import\RowProblem;
use App\Support\Import\SpreadsheetImporter;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Str;

/**
 * Opening stock at go-live (F-02).
 *
 * Posted as one document, not one per row: `postDocument()` claims the
 * document once and writes every line in a single transaction, which is both
 * how the all-or-nothing rule is honoured and why 3,000 lines finish inside
 * the two-minute target.
 *
 * It goes through the posting kernel like any other movement — a bulk insert
 * into `stock_ledger` would skip the balance, the moving average and the
 * journal, and opening stock with no cost is a system that starts wrong.
 */
final class OpeningStockImporter extends SpreadsheetImporter
{
    private string $reference;

    public function __construct(
        private readonly StockPosting $posting,
        private readonly ResolveStockBatch $resolveBatch,
        private ?DateTimeInterface $countedOn = null,
    ) {
        $this->reference = (string) Str::uuid7();
        $this->countedOn ??= CarbonImmutable::now();
    }

    public function columns(): array
    {
        return [
            'warehouse_code' => 'Warehouse code',
            'sku' => 'SKU',
            'qty_base' => 'Quantity (base units)',
            'unit_cost' => 'Unit cost',
            'batch_number' => 'Batch',
            'production_date' => 'Production date (YYYY-MM-DD)',
            'expiry_date' => 'Expiry date (YYYY-MM-DD)',
        ];
    }

    public function example(): array
    {
        return [
            'warehouse_code' => 'WH-01', 'sku' => 'KOPI-01', 'qty_base' => '480',
            'unit_cost' => '2500', 'batch_number' => 'B-2608',
            'production_date' => '2026-08-01', 'expiry_date' => '',
        ];
    }

    protected function check(ImportRow $row): array
    {
        $problems = [];

        foreach (['warehouse_code', 'sku', 'qty_base', 'unit_cost'] as $required) {
            if ($row->get($required) === null) {
                $problems[] = new RowProblem($row->line, $this->columns()[$required], 'is required');
            }
        }

        foreach (['qty_base', 'unit_cost'] as $numeric) {
            $value = $row->get($numeric);
            if ($value !== null && ! is_numeric($value)) {
                $problems[] = new RowProblem($row->line, $this->columns()[$numeric], sprintf('"%s" is not a number', $value));
            } elseif ($value !== null && (float) $value <= 0) {
                $problems[] = new RowProblem($row->line, $this->columns()[$numeric], 'must be more than zero');
            }
        }

        if (($code = $row->get('warehouse_code')) !== null && ! Warehouse::where('code', $code)->exists()) {
            $problems[] = new RowProblem($row->line, 'Warehouse code', sprintf('no warehouse has the code "%s"', $code));
        }

        if (($sku = $row->get('sku')) !== null && ! Product::where('sku', $sku)->exists()) {
            $problems[] = new RowProblem($row->line, 'SKU', sprintf('no product has the SKU "%s" — import products first', $sku));
        }

        foreach (['production_date', 'expiry_date'] as $date) {
            $value = $row->get($date);
            if ($value !== null && ! $this->isDate($value)) {
                $problems[] = new RowProblem($row->line, $this->columns()[$date], sprintf('"%s" is not a date like 2026-08-26', $value));
            }
        }

        return $problems;
    }

    protected function persist(array $rows): int
    {
        $movements = [];
        $problems = [];

        foreach ($rows as $row) {
            /** @var Product $product */
            $product = Product::where('sku', $row->get('sku'))->firstOrFail();
            $warehouse = Warehouse::where('code', $row->get('warehouse_code'))->firstOrFail();

            try {
                $batch = ($this->resolveBatch)(
                    $product,
                    $row->get('batch_number'),
                    $this->countedOn,
                    $this->date($row->get('production_date')),
                    $this->date($row->get('expiry_date')),
                );
            } catch (DomainException $exception) {
                $problems[] = new RowProblem($row->line, null, $exception->getMessage());

                continue;
            }

            $movements[] = new StockMovement(
                warehouseId: $warehouse->getKey(),
                productId: $product->getKey(),
                quantity: $row->get('qty_base'),
                unitCost: $row->get('unit_cost'),
                referenceType: 'opening_balance',
                referenceId: $this->reference,
                transactionDate: $this->countedOn,
                batchId: $batch?->getKey(),
                description: 'Opening stock',
            );
        }

        if ($problems !== []) {
            throw new ImportHasProblems($problems);
        }

        // One claim, one transaction, one journal per line.
        $this->posting->postDocument($movements);

        return count($movements);
    }

    private function isDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
    }
}
