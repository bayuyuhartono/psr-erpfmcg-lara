<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Actions\SubmitSalesOrder as Submitter;
use App\Domain\Sales\Models\SalesOrder;
use App\Models\User;
use App\Support\Posting\PostingTransaction;

/**
 * Lets a held order through, on a named person's authority (F-30).
 *
 * The permission check belongs to the state machine, so this cannot be
 * bypassed by transitioning the order some other way. What is recorded is who
 * released it and when — a hold that could be cleared anonymously would tell
 * an owner nothing about who kept extending credit to a bad payer.
 */
final class ReleaseCreditHold
{
    public function __construct(private readonly Submitter $submitter) {}

    public function __invoke(SalesOrder $order, User $actor, ?string $note = null): SalesOrder
    {
        return PostingTransaction::run(function () use ($order, $actor, $note): SalesOrder {
            $order->refresh();

            // The hold detail stays on the record. What triggered the hold is
            // as much a part of the history as the decision to override it.
            $order->transitionTo('approved', $actor, [
                'released_by' => $actor->getKey(),
                'released_at' => now(),
                'hold_detail' => $note === null
                    ? $order->hold_detail
                    : trim($order->hold_detail.' — released: '.$note),
            ]);

            $this->submitter->reserve($order);

            return $order->refresh();
        });
    }
}
