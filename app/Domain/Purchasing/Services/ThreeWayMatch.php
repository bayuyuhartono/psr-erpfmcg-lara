<?php

namespace App\Domain\Purchasing\Services;

use App\Domain\Masters\Models\CompanySetting;
use App\Domain\Purchasing\Models\SupplierInvoice;
use App\Domain\Purchasing\Models\SupplierInvoiceLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Order price, receipt quantity, invoice amount — checked against each other
 * before anyone is paid (F-11).
 *
 * The quantity comes from the receipt because that is what actually arrived,
 * and the price from the purchase order because that is what was agreed. An
 * invoice that matches neither is the case this exists to catch: paying for
 * goods that were never delivered, at a price nobody approved.
 *
 * Invoice line quantities are read in the unit the receipt line recorded, and
 * converted with the factor that line snapshotted — not with today's.
 */
final class ThreeWayMatch
{
    public function __invoke(SupplierInvoice $invoice): MatchResult
    {
        $variances = [];
        $expectedTotal = BigDecimal::zero();
        $invoicedTotal = BigDecimal::zero();

        foreach ($invoice->supplierInvoiceLines()->with('goodsReceiptLine.purchaseOrderLine', 'product')->get() as $line) {
            $expected = $this->expectedFor($line);
            $invoiced = BigDecimal::of($line->line_total)->toScale(2, RoundingMode::HALF_UP);

            $expectedTotal = $expectedTotal->plus($expected);
            $invoicedTotal = $invoicedTotal->plus($invoiced);

            if (! $expected->isEqualTo($invoiced)) {
                $variances[] = new MatchVariance(
                    $line->product?->sku ?? 'unmatched line',
                    $expected,
                    $invoiced,
                    $this->explain($line),
                );
            }
        }

        return new MatchResult(
            $variances,
            $expectedTotal->toScale(2, RoundingMode::HALF_UP),
            $invoicedTotal->toScale(2, RoundingMode::HALF_UP),
            $this->toleranceFor($expectedTotal),
        );
    }

    /**
     * The stricter of the two thresholds.
     *
     * A percentage alone would wave through millions on a large invoice; a
     * flat amount alone would block rounding on a small one. Taking the lower
     * is deliberately cautious — a blocked invoice is not lost, it waits for a
     * supervisor, which is cheaper than paying for goods that never arrived.
     */
    private function toleranceFor(BigDecimal $expectedTotal): BigDecimal
    {
        $settings = CompanySetting::query()->first();
        $percent = BigDecimal::of($settings?->match_tolerance_percent ?? '2.00');
        $amount = BigDecimal::of($settings?->match_tolerance_amount ?? '50000.00');
        $fromPercent = $expectedTotal->abs()->multipliedBy($percent)->dividedBy(100, 2, RoundingMode::HALF_UP);

        return $fromPercent->isLessThan($amount) ? $fromPercent : $amount->toScale(2, RoundingMode::HALF_UP);
    }

    /** Received quantity at the ordered price. */
    private function expectedFor(SupplierInvoiceLine $line): BigDecimal
    {
        $receiptLine = $line->goodsReceiptLine;

        if ($receiptLine === null) {
            // Nothing to match against: the whole amount is unexplained.
            return BigDecimal::zero();
        }

        $orderLine = $receiptLine->purchaseOrderLine;
        $unitPrice = $orderLine !== null
            ? BigDecimal::of($orderLine->unit_price)
                ->multipliedBy(BigDecimal::of(100)->minus($orderLine->discount_percent))
                ->dividedBy(100, 4, RoundingMode::HALF_UP)
            : BigDecimal::of($receiptLine->unit_cost);

        // The receipt recorded its quantity in its own unit; price it in the
        // same one.
        $receivedInReceiptUnit = BigDecimal::of($receiptLine->qty_base)
            ->dividedBy($receiptLine->qty_in_base, 4, RoundingMode::HALF_UP);

        return $receivedInReceiptUnit->multipliedBy($unitPrice)->toScale(2, RoundingMode::HALF_UP);
    }

    private function explain(SupplierInvoiceLine $line): string
    {
        $receiptLine = $line->goodsReceiptLine;

        if ($receiptLine === null) {
            return 'invoiced against no goods receipt';
        }

        $invoicedQty = BigDecimal::of($line->qty ?? 0);
        $receivedQty = BigDecimal::of($receiptLine->qty_base)
            ->dividedBy($receiptLine->qty_in_base, 4, RoundingMode::HALF_UP);

        if (! $invoicedQty->isEqualTo($receivedQty)) {
            return sprintf('invoiced %s, received %s', $invoicedQty->stripTrailingZeros(), $receivedQty->stripTrailingZeros());
        }

        return 'price differs from the order';
    }
}
