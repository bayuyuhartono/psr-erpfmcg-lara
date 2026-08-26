<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\PostdatedCheque;
use App\Domain\Sales\Models\SalesInvoice;
use App\Models\User;
use App\Support\Posting\JournalDocument;
use App\Support\Posting\JournalPoster;
use App\Support\Posting\JournalPosting;
use App\Support\Posting\PostingTransaction;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What happened when the cheque was presented (F-51).
 *
 *   cleared: DR 1101 Cash           CR 1105 Postdated Cheques Receivable
 *   bounced: DR 1102 Receivables    CR 1105 Postdated Cheques Receivable
 *
 * A bounced cheque puts the debt back where it started and reopens the
 * invoices it was covering, because nothing was actually paid. That reversal
 * is the reason cheques are tracked as their own thing: without it, an invoice
 * marked paid in March stays paid even though the money never arrived.
 */
final class ResolvePostdatedCheque
{
    public function __construct(private readonly JournalPoster $journals) {}

    public function clear(PostdatedCheque $cheque, ?DateTimeInterface $on = null, ?User $actor = null): PostdatedCheque
    {
        $on = CarbonImmutable::instance($on ?? CarbonImmutable::now())->startOfDay();

        return PostingTransaction::run(function () use ($cheque, $on, $actor): PostdatedCheque {
            $cheque->transitionTo('cleared', $actor, ['resolved_on' => $on->format('Y-m-d')]);

            $this->journals->post(new JournalDocument(
                documentType: 'cheque_cleared',
                referenceId: $cheque->getKey(),
                entryDate: $on,
                postings: [
                    JournalPosting::debit('cash', $cheque->amount, partnerId: $cheque->partner_id),
                    JournalPosting::credit('cheque', $cheque->amount, partnerId: $cheque->partner_id),
                ],
                description: sprintf('Cheque %s cleared', $cheque->cheque_number),
                actorId: $actor?->getKey(),
            ));

            return $cheque->refresh();
        });
    }

    /** @param  list<string>  $reopenInvoiceIds  invoices the cheque was covering */
    public function bounce(
        PostdatedCheque $cheque,
        string $reason,
        array $reopenInvoiceIds = [],
        ?DateTimeInterface $on = null,
        ?User $actor = null,
    ): PostdatedCheque {
        $on = CarbonImmutable::instance($on ?? CarbonImmutable::now())->startOfDay();

        return PostingTransaction::run(function () use ($cheque, $reason, $reopenInvoiceIds, $on, $actor): PostdatedCheque {
            $cheque->transitionTo('bounced', $actor, [
                'resolved_on' => $on->format('Y-m-d'),
                'bounce_reason' => $reason,
            ]);

            foreach ($reopenInvoiceIds as $invoiceId) {
                /** @var SalesInvoice $invoice */
                $invoice = SalesInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

                // Whatever this cheque was covering is owed again.
                $paid = $invoice->paid_amount->minus($cheque->amount);
                $paid = $paid->isNegative() ? $paid->multipliedBy(0) : $paid;

                $invoice->transitionTo(
                    $paid->isPositive() ? 'partially_paid' : 'posted',
                    $actor,
                    ['paid_amount' => $paid],
                );
            }

            $this->journals->post(new JournalDocument(
                documentType: 'cheque_bounced',
                referenceId: $cheque->getKey(),
                entryDate: $on,
                postings: [
                    JournalPosting::debit('receivable', $cheque->amount, partnerId: $cheque->partner_id),
                    JournalPosting::credit('cheque', $cheque->amount, partnerId: $cheque->partner_id),
                ],
                description: sprintf('Cheque %s bounced: %s', $cheque->cheque_number, $reason),
                actorId: $actor?->getKey(),
            ));

            return $cheque->refresh();
        });
    }
}
