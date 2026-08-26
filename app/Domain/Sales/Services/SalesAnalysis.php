<?php

namespace App\Domain\Sales\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * What sold, to whom, and what was made on it (F-70 §8.6).
 *
 * Revenue comes from posted invoices — what was actually billed — and cost
 * from the delivery lines behind them, which carry the moving average the
 * goods left at. Margin computed against a price list instead would be a
 * guess; this is the figure that reconciles to the ledger.
 *
 * Cost is only meaningful to someone allowed to see it, so callers must have
 * already passed the `view-cost` gate (F-70).
 */
final class SalesAnalysis
{
    private const DIMENSIONS = [
        'product' => ['products.sku', 'products.name'],
        'brand' => ['brands.code', 'brands.name'],
        'customer' => ['business_partners.code', 'business_partners.name'],
        'salesperson' => ['salespeople.code', 'salespeople.name'],
    ];

    /**
     * @return list<array{code: string, name: string, qty: string, revenue: string, cost: string, margin: string, margin_percent: string}>
     */
    public function by(string $dimension, DateTimeInterface $from, DateTimeInterface $to): array
    {
        [$code, $name] = self::DIMENSIONS[$dimension] ?? throw new \InvalidArgumentException(
            sprintf('Cannot break sales down by "%s". Try: %s.', $dimension, implode(', ', array_keys(self::DIMENSIONS))),
        );

        $rows = $this->base($from, $to)
            ->selectRaw("{$code} AS code, {$name} AS name")
            ->selectRaw('COALESCE(SUM(sales_invoice_lines.qty), 0) AS qty')
            ->selectRaw('COALESCE(SUM(sales_invoice_lines.line_total), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(delivery_lines.qty_accepted * delivery_lines.unit_cost), 0) AS cost')
            ->groupBy($code, $name)
            ->orderByDesc('revenue')
            ->get();

        return $rows->map(function (object $row): array {
            $revenue = BigDecimal::of($row->revenue)->toScale(2, RoundingMode::HALF_UP);
            $cost = BigDecimal::of($row->cost)->toScale(2, RoundingMode::HALF_UP);
            $margin = $revenue->minus($cost);

            return [
                'code' => (string) ($row->code ?? '—'),
                'name' => (string) ($row->name ?? 'unattributed'),
                'qty' => (string) BigDecimal::of($row->qty)->toScale(4),
                'revenue' => (string) $revenue,
                'cost' => (string) $cost,
                'margin' => (string) $margin,
                'margin_percent' => $revenue->isZero()
                    ? '0.0'
                    : (string) $margin->multipliedBy(100)->dividedBy($revenue, 1, RoundingMode::HALF_UP),
            ];
        })->all();
    }

    /** @return array{revenue: string, cost: string, margin: string, margin_percent: string} */
    public function grossMargin(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $totals = $this->base($from, $to)
            ->selectRaw('COALESCE(SUM(sales_invoice_lines.line_total), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(delivery_lines.qty_accepted * delivery_lines.unit_cost), 0) AS cost')
            ->first();

        $revenue = BigDecimal::of($totals->revenue)->toScale(2, RoundingMode::HALF_UP);
        $cost = BigDecimal::of($totals->cost)->toScale(2, RoundingMode::HALF_UP);
        $margin = $revenue->minus($cost);

        return [
            'revenue' => (string) $revenue,
            'cost' => (string) $cost,
            'margin' => (string) $margin,
            'margin_percent' => $revenue->isZero()
                ? '0.0'
                : (string) $margin->multipliedBy(100)->dividedBy($revenue, 1, RoundingMode::HALF_UP),
        ];
    }

    private function base(DateTimeInterface $from, DateTimeInterface $to)
    {
        return DB::table('sales_invoice_lines')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->join('products', 'products.id', '=', 'sales_invoice_lines.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->join('business_partners', 'business_partners.id', '=', 'sales_invoices.customer_id')
            ->leftJoin('salespeople', 'salespeople.id', '=', 'business_partners.salesperson_id')
            // The delivery line is where the cost the goods actually left at
            // was recorded when the load shipped.
            ->leftJoin('delivery_lines', 'delivery_lines.id', '=', 'sales_invoice_lines.delivery_line_id')
            ->whereIn('sales_invoices.status', ['posted', 'exchanged', 'partially_paid', 'paid'])
            ->whereDate('sales_invoices.invoice_date', '>=', CarbonImmutable::instance($from)->format('Y-m-d'))
            ->whereDate('sales_invoices.invoice_date', '<=', CarbonImmutable::instance($to)->format('Y-m-d'));
    }
}
