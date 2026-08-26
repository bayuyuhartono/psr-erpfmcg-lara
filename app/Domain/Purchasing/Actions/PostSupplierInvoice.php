<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Exceptions\MatchVarianceTooLarge;
use App\Domain\Purchasing\Models\SupplierInvoice;
use App\Domain\Purchasing\Services\ThreeWayMatch;
use App\Models\User;
use App\Support\Posting\JournalDocument;
use App\Support\Posting\JournalPoster;
use App\Support\Posting\JournalPosting;
use App\Support\Posting\PostingGuard;
use App\Support\Posting\PostingTransaction;
use Brick\Math\BigDecimal;

/**
 * Posts a supplier invoice, clearing what the goods receipt parked in GR/IR
 * and owing the supplier for real (F-11).
 *
 *   DR 2103 Goods Received Not Invoiced   the goods, at what was received
 *   DR 1104 VAT Input                     the tax we reclaim
 *      CR 2101 Trade Payables             what we now owe
 *
 * Nothing posts until the three-way match agrees, or a supervisor says to post
 * it anyway. That decision is recorded as an approval, not as a quiet edit to
 * make the numbers line up.
 */
final class PostSupplierInvoice
{
    public function __construct(
        private readonly ThreeWayMatch $match,
        private readonly JournalPoster $journals,
        private readonly PostingGuard $guard,
    ) {}

    public function __invoke(SupplierInvoice $invoice, ?User $actor = null, bool $approveVariance = false): SupplierInvoice
    {
        $match = ($this->match)($invoice);

        if (! $match->isWithinTolerance()) {
            $this->assertApproved($invoice, $actor, $approveVariance, $match->difference(), $match->describe(), $match->tolerance);
        }

        return PostingTransaction::run(function () use ($invoice, $actor): SupplierInvoice {
            // A deadlock retry re-runs this closure, and $invoice still
            // carries whatever the rolled-back attempt put on it.
            $invoice->refresh();

            // State machine first, claim underneath — the same order as a goods
            // receipt, so a second click reads as "already posted" in terms of
            // the document rather than the posting reference.
            $invoice->transitionTo('posted', $actor);
            $this->guard->claim('supplier_invoice', $invoice->getKey(), $actor?->getKey());

            $postings = [JournalPosting::debit('grir', $invoice->subtotal, partnerId: $invoice->supplier_id)];

            if ($invoice->vat_amount->isPositive()) {
                $postings[] = JournalPosting::debit('vat_in', $invoice->vat_amount, partnerId: $invoice->supplier_id);
            }

            $postings[] = JournalPosting::credit('payable', $invoice->total, partnerId: $invoice->supplier_id);

            $this->journals->post(new JournalDocument(
                documentType: 'supplier_invoice',
                referenceId: $invoice->getKey(),
                entryDate: $invoice->invoice_date,
                postings: $postings,
                description: sprintf('Supplier invoice %s', $invoice->document_number),
                actorId: $actor?->getKey(),
            ));

            return $invoice->refresh();
        });
    }

    private function assertApproved(
        SupplierInvoice $invoice,
        ?User $actor,
        bool $approveVariance,
        BigDecimal $difference,
        string $detail,
        BigDecimal $tolerance,
    ): void {
        $summary = sprintf(
            'Invoice %s is out by %s against the order and receipt, over the %s tolerance. %s',
            $invoice->document_number,
            $difference,
            $tolerance,
            $detail,
        );

        if (! $approveVariance) {
            throw new MatchVarianceTooLarge($summary.' A supervisor must approve it before it posts.');
        }

        if ($actor === null || ! $actor->hasPermission('supplier_invoice.approve_variance')) {
            throw new MatchVarianceTooLarge($summary.' '.($actor?->full_name ?? 'Nobody').' may not approve a variance this size.');
        }
    }
}
