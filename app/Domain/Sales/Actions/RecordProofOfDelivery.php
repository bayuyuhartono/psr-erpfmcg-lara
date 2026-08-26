<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\DeliveryLine;
use App\Domain\Sales\Models\DeliveryPod;
use App\Domain\Sales\Models\SalesReturn;
use App\Domain\Sales\Models\SalesReturnLine;
use App\Models\User;
use App\Support\Posting\DocumentNumberer;
use App\Support\Posting\PostingTransaction;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Records what the outlet actually took (F-42).
 *
 * The accepted quantity is the one that gets invoiced, because billing for
 * what was loaded rather than for what was received is the fastest way to
 * lose an argument at the exchange desk.
 *
 * Anything refused comes back on the truck, so a shortfall raises a draft
 * sales return — the goods are physically in the vehicle and have to land
 * somewhere. It is left in draft because whether they are resellable is a
 * judgement made at the warehouse door, not at the outlet.
 */
final class RecordProofOfDelivery
{
    public function __construct(private readonly DocumentNumberer $numberer) {}

    /** @param  array<string, string>  $acceptedByLine  delivery line ID => quantity accepted */
    public function __invoke(
        Delivery $delivery,
        array $acceptedByLine = [],
        ?string $recipientName = null,
        ?DateTimeInterface $receivedAt = null,
        ?User $actor = null,
        ?string $note = null,
    ): DeliveryPod {
        $receivedAt = CarbonImmutable::instance($receivedAt ?? CarbonImmutable::now());

        return PostingTransaction::run(function () use ($delivery, $acceptedByLine, $recipientName, $receivedAt, $actor, $note): DeliveryPod {
            $delivery->refresh();

            $shortfalls = [];
            $accepted = BigDecimal::zero();
            $shipped = BigDecimal::zero();

            foreach ($delivery->deliveryLines()->get() as $line) {
                /** @var DeliveryLine $line */
                $take = BigDecimal::of($acceptedByLine[$line->getKey()] ?? (string) $line->qty_base)->toScale(4);
                $line->update(['qty_accepted' => $take]);

                $shipped = $shipped->plus($line->qty_base);
                $accepted = $accepted->plus($take);

                if ($take->isLessThan($line->qty_base)) {
                    $shortfalls[] = [$line, $line->qty_base->minus($take)];
                }
            }

            $outcome = match (true) {
                $accepted->isZero() => 'refused',
                $accepted->isLessThan($shipped) => 'partial',
                default => 'full',
            };

            $delivery->transitionTo($outcome === 'refused' ? 'failed' : 'delivered', $actor);

            if ($shortfalls !== []) {
                $this->raiseReturn($delivery, $shortfalls);
            }

            return DeliveryPod::create([
                'delivery_id' => $delivery->getKey(),
                'received_at' => $receivedAt,
                'recipient_name' => $recipientName,
                'outcome' => $outcome,
                'note' => $note,
            ]);
        });
    }

    /** @param  list<array{0: DeliveryLine, 1: BigDecimal}>  $shortfalls */
    private function raiseReturn(Delivery $delivery, array $shortfalls): SalesReturn
    {
        $return = SalesReturn::create([
            'document_number' => $this->numberer->next('SR', $delivery->delivery_date),
            'customer_id' => $delivery->customer_id,
            'warehouse_id' => $delivery->warehouse_id,
            'return_date' => $delivery->delivery_date->format('Y-m-d'),
            'reason' => 'unsold',
            'resellable' => true,
        ]);

        foreach ($shortfalls as [$line, $quantity]) {
            SalesReturnLine::create([
                'sales_return_id' => $return->getKey(),
                'product_id' => $line->product_id,
                'batch_id' => $line->batch_id,
                'qty_base' => $quantity,
                'unit_price' => '0.00',
            ]);
        }

        return $return;
    }
}
