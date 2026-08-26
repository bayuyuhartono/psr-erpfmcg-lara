<?php

namespace App\Domain\Sales\Services;

use Brick\Math\BigDecimal;

/** Where a customer stands, and why an order would be held. */
final readonly class CreditStatus
{
    public function __construct(
        public BigDecimal $limit,
        public BigDecimal $outstanding,
        public BigDecimal $overdue,
        public int $overdueInvoices,
        public bool $blocked,
        public ?string $blockReason,
    ) {}

    public function available(): BigDecimal
    {
        return $this->limit->minus($this->outstanding);
    }

    /** @return 'over_credit_limit'|'overdue_invoices'|'customer_blocked'|null */
    public function holdReasonFor(BigDecimal $orderTotal): ?string
    {
        return match (true) {
            $this->blocked => 'customer_blocked',
            $this->overdueInvoices > 0 => 'overdue_invoices',
            // A zero limit means no credit has been granted, not unlimited
            // credit. Cash customers are the ones with no orders on account.
            $this->outstanding->plus($orderTotal)->isGreaterThan($this->limit) => 'over_credit_limit',
            default => null,
        };
    }

    public function explain(BigDecimal $orderTotal): string
    {
        return match ($this->holdReasonFor($orderTotal)) {
            'customer_blocked' => sprintf('Customer is on hold: %s', $this->blockReason ?? 'no reason recorded'),
            'overdue_invoices' => sprintf('%d invoice(s) overdue, %s outstanding', $this->overdueInvoices, $this->overdue),
            'over_credit_limit' => sprintf(
                'Limit %s, already owes %s, this order %s — over by %s',
                $this->limit,
                $this->outstanding,
                $orderTotal,
                $this->outstanding->plus($orderTotal)->minus($this->limit),
            ),
            default => 'Within limit',
        };
    }
}
