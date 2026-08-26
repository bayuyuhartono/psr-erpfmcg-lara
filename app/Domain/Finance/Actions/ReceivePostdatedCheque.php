<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\PostdatedCheque;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Sales\Models\SalesInvoice;
use App\Models\User;
use App\Support\Posting\JournalDocument;
use App\Support\Posting\JournalPoster;
use App\Support\Posting\JournalPosting;
use App\Support\Posting\PostingTransaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Takes a postdated cheque, which is not the same as taking money (F-51).
 *
 *   DR 1105 Postdated Cheques Receivable    CR 1102 Trade Receivables
 *
 * The debt moves from the customer to the cheque. Cash is untouched, so the
 * cash position stays true — which is the whole point, because a distributor
 * counting a drawer of postdated cheques as money is exactly the one who
 * cannot pay the principal on Friday.
 *
 * The invoice is marked settled, because as far as the outlet is concerned it
 * has been. If the cheque bounces, that is undone.
 */
final class ReceivePostdatedCheque
{
    public function __construct(private readonly JournalPoster $journals) {}

    /** @param  array<string, string>  $againstInvoices  invoice ID => amount covered */
    public function __invoke(
        BusinessPartner $customer,
        string $chequeNumber,
        BigDecimal|string $amount,
        DateTimeInterface $dueOn,
        array $againstInvoices = [],
        ?string $bankName = null,
        ?DateTimeInterface $receivedOn = null,
        ?User $actor = null,
    ): PostdatedCheque {
        $amount = BigDecimal::of($amount)->toScale(2, RoundingMode::HALF_UP);
        $receivedOn = CarbonImmutable::instance($receivedOn ?? CarbonImmutable::now())->startOfDay();

        return PostingTransaction::run(function () use ($customer, $chequeNumber, $amount, $dueOn, $againstInvoices, $bankName, $receivedOn, $actor): PostdatedCheque {
            $cheque = PostdatedCheque::create([
                'partner_id' => $customer->getKey(),
                'cheque_number' => $chequeNumber,
                'bank_name' => $bankName,
                'received_on' => $receivedOn->format('Y-m-d'),
                'due_on' => CarbonImmutable::instance($dueOn)->format('Y-m-d'),
                'amount' => $amount,
            ]);

            foreach ($againstInvoices as $invoiceId => $covered) {
                /** @var SalesInvoice $invoice */
                $invoice = SalesInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
                $paid = $invoice->paid_amount->plus(BigDecimal::of($covered));

                $invoice->transitionTo(
                    $paid->isGreaterThanOrEqualTo($invoice->total) ? 'paid' : 'partially_paid',
                    $actor,
                    ['paid_amount' => $paid],
                );
            }

            $this->journals->post(new JournalDocument(
                documentType: 'cheque_received',
                referenceId: $cheque->getKey(),
                entryDate: $receivedOn,
                postings: [
                    JournalPosting::debit('cheque', $amount, partnerId: $customer->getKey()),
                    JournalPosting::credit('receivable', $amount, partnerId: $customer->getKey()),
                ],
                description: sprintf('Cheque %s from %s, due %s', $chequeNumber, $customer->name, $cheque->due_on->format('Y-m-d')),
                actorId: $actor?->getKey(),
            ));

            return $cheque->refresh();
        });
    }
}
