<?php

namespace App\Domain\Finance\Services;

use App\Domain\Sales\Models\SalesInvoice;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What is owed, and how late (F-53).
 *
 * Grouped by customer and by salesperson, because they answer different
 * questions: an owner chasing an outlet needs the first, and an owner working
 * out why one route collects and another does not needs the second.
 */
final class ReceivablesAging
{
    private const OPEN = ['posted', 'exchanged', 'partially_paid'];

    /** Days overdue, upper bound. Anything beyond the last is "over 90". */
    private const BUCKETS = [0, 30, 60, 90];

    /**
     * @return array{
     *     buckets: array<string, string>,
     *     total: string,
     *     by_customer: list<array<string, string>>,
     *     by_salesperson: list<array<string, string>>
     * }
     */
    public function __invoke(?DateTimeInterface $on = null): array
    {
        $on = CarbonImmutable::instance($on ?? CarbonImmutable::now())->startOfDay();

        $invoices = SalesInvoice::query()
            ->with(['customer.salesperson'])
            ->whereIn('status', self::OPEN)
            ->get();

        $buckets = array_fill_keys($this->bucketLabels(), BigDecimal::zero());
        $byCustomer = [];
        $bySalesperson = [];
        $total = BigDecimal::zero();

        foreach ($invoices as $invoice) {
            $owing = $invoice->total->minus($invoice->paid_amount);

            if (! $owing->isPositive()) {
                continue;
            }

            $label = $this->bucketFor((int) $on->diffInDays($invoice->due_date->startOfDay(), false));
            $buckets[$label] = $buckets[$label]->plus($owing);
            $total = $total->plus($owing);

            $customer = $invoice->customer->name;
            $byCustomer[$customer] = ($byCustomer[$customer] ?? BigDecimal::zero())->plus($owing);

            $salesperson = $invoice->customer->salesperson?->name ?? 'unassigned';
            $bySalesperson[$salesperson] = ($bySalesperson[$salesperson] ?? BigDecimal::zero())->plus($owing);
        }

        return [
            'buckets' => array_map(fn (BigDecimal $amount): string => (string) $amount->toScale(2), $buckets),
            'total' => (string) $total->toScale(2),
            'by_customer' => $this->rank($byCustomer),
            'by_salesperson' => $this->rank($bySalesperson),
        ];
    }

    /** @return list<string> */
    private function bucketLabels(): array
    {
        $labels = ['Not yet due'];

        foreach (self::BUCKETS as $index => $upper) {
            if ($upper === 0) {
                continue;
            }

            $labels[] = sprintf('%d–%d days', self::BUCKETS[$index - 1] + 1, $upper);
        }

        return [...$labels, 'Over 90 days'];
    }

    private function bucketFor(int $daysUntilDue): string
    {
        if ($daysUntilDue >= 0) {
            return 'Not yet due';
        }

        $overdue = abs($daysUntilDue);

        foreach (self::BUCKETS as $index => $upper) {
            if ($upper > 0 && $overdue <= $upper) {
                return sprintf('%d–%d days', self::BUCKETS[$index - 1] + 1, $upper);
            }
        }

        return 'Over 90 days';
    }

    /**
     * @param  array<string, BigDecimal>  $totals
     * @return list<array<string, string>>
     */
    private function rank(array $totals): array
    {
        uasort($totals, fn (BigDecimal $a, BigDecimal $b): int => $b->compareTo($a));

        return array_map(
            fn (string $name, BigDecimal $amount): array => ['name' => $name, 'outstanding' => (string) $amount->toScale(2)],
            array_keys($totals),
            array_values($totals),
        );
    }
}
