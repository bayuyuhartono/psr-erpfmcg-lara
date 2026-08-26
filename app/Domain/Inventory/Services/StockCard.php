<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Masters\Models\Product;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Every movement of one product, in order, with a running balance (F-21).
 *
 * Read straight from `stock_ledger` rather than from any summary, because the
 * ledger is the truth and this is the report someone opens when the truth is in
 * question. A card that agreed with a cache rather than with the ledger would
 * be worth nothing at exactly the moment it is needed.
 *
 * The opening balance is computed from everything before the window, so a card
 * for one month still starts from the right number.
 */
final class StockCard
{
    /**
     * Document numbers by reference type — the drill-through target for each
     * kind of movement.
     */
    private const DOCUMENTS = [
        'goods_receipt' => 'goods_receipts',
        'purchase_return' => 'purchase_returns',
        'delivery' => 'deliveries',
        'sales_return' => 'sales_returns',
        'adjustment' => 'stock_adjustments',
        'transfer_in' => 'stock_transfers',
        'transfer_out' => 'stock_transfers',
        'consignment_settlement' => 'consignment_settlements',
    ];

    /** @return list<StockCardEntry> */
    public function for(
        Product $product,
        ?string $warehouseId = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
    ): array {
        $rows = $this->rows($product, $warehouseId)
            ->when($from, fn ($query, $date) => $query->whereDate('transaction_date', '>=', CarbonImmutable::instance($date)->format('Y-m-d')))
            ->when($to, fn ($query, $date) => $query->whereDate('transaction_date', '<=', CarbonImmutable::instance($date)->format('Y-m-d')))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        [$quantity, $value] = $this->openingBalance($product, $warehouseId, $from);
        $numbers = $this->documentNumbers($rows->pluck('ref_type', 'ref_id')->all());

        $entries = [];

        foreach ($rows as $row) {
            $quantity = $quantity->plus($row->qty_base);
            $value = $value->plus($row->value);

            $entries[] = new StockCardEntry(
                ledgerId: $row->id,
                date: CarbonImmutable::parse($row->transaction_date),
                referenceType: $row->ref_type,
                referenceId: $row->ref_id,
                documentNumber: $numbers[$row->ref_id] ?? null,
                batchNumber: $row->batch?->batch_number,
                quantity: $row->qty_base,
                unitCost: $row->unit_cost,
                value: $row->value,
                runningQuantity: $quantity->toScale(4),
                runningValue: $value->toScale(2),
                isReversal: $row->reverses_ledger_id !== null,
            );
        }

        return $entries;
    }

    /** @return array{BigDecimal, BigDecimal} */
    public function openingBalance(Product $product, ?string $warehouseId, ?DateTimeInterface $from): array
    {
        if ($from === null) {
            return [BigDecimal::zero()->toScale(4), BigDecimal::zero()->toScale(2)];
        }

        $totals = $this->rows($product, $warehouseId)
            ->whereDate('transaction_date', '<', CarbonImmutable::instance($from)->format('Y-m-d'))
            ->selectRaw('COALESCE(SUM(qty_base), 0) as qty, COALESCE(SUM(value), 0) as value')
            ->first();

        return [BigDecimal::of($totals->qty)->toScale(4), BigDecimal::of($totals->value)->toScale(2)];
    }

    /** @param  array<string, string>  $referenceTypesById */
    private function documentNumbers(array $referenceTypesById): array
    {
        $byType = [];

        foreach ($referenceTypesById as $id => $type) {
            $byType[$type][] = $id;
        }

        $numbers = [];

        foreach ($byType as $type => $ids) {
            $table = self::DOCUMENTS[$type] ?? null;

            if ($table === null) {
                continue;
            }

            $numbers += DB::table($table)->whereIn('id', $ids)->pluck('document_number', 'id')->all();
        }

        return $numbers;
    }

    private function rows(Product $product, ?string $warehouseId)
    {
        return StockLedger::query()
            ->with('batch')
            ->where('product_id', $product->getKey())
            ->when($warehouseId, fn ($query, $id) => $query->where('warehouse_id', $id));
    }
}
