<?php

namespace App\Support\Posting;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Inventory\Models\StockLedger;
use App\Models\User;
use App\Support\Posting\Exceptions\AlreadyReversed;
use App\Support\Posting\Exceptions\NothingToReverse;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Undoes a posted document by posting its opposite.
 *
 * Nothing is edited and nothing is deleted: each original ledger row gets a
 * mirror row with the sign flipped, and each original journal entry is marked
 * reversed while the counter-entry stands beside it. The document's history
 * still reads as what happened, followed by what undid it.
 *
 * The counter-postings go through StockPosting like any other movement, so the
 * counter-journal falls out of the sign flip rather than being assembled by
 * hand — one code path writes stock, reversals included.
 *
 * They use the single-movement `post()` rather than `postDocument()`: the
 * document's posting claim already belongs to the original, and reversing is
 * not posting it again. Reversing twice is stopped by the unique index on
 * `stock_ledger.reverses_ledger_id` instead.
 */
final class StockReversal
{
    public function __construct(private readonly StockPosting $posting) {}

    /**
     * @param  DateTimeInterface|null  $date  when the reversal posts. Defaults to today
     *                                        rather than the original date: a correction
     *                                        found in September belongs in September,
     *                                        and August may well be closed.
     */
    public function reverse(
        string $referenceType,
        string $referenceId,
        ?User $actor = null,
        ?DateTimeInterface $date = null,
        ?string $description = null,
        ?string $partnerId = null,
        ?string $branchId = null,
    ): StockReversalResult {
        return PostingTransaction::run(function () use (
            $referenceType, $referenceId, $actor, $date, $description, $partnerId, $branchId
        ): StockReversalResult {
            $originals = StockLedger::withoutGlobalScopes()
                ->where('ref_type', $referenceType)
                ->where('ref_id', $referenceId)
                ->whereNull('reverses_ledger_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($originals->isEmpty()) {
                throw new NothingToReverse(sprintf(
                    'No stock was posted for %s %s, so there is nothing to reverse.',
                    $referenceType,
                    $referenceId,
                ));
            }

            $this->assertNotAlreadyReversed($originals->modelKeys(), $referenceType, $referenceId);

            // Captured before the counter-postings run, since those write
            // journal entries against the same document reference.
            $entries = JournalEntry::query()
                ->where('ref_type', $referenceType)
                ->where('ref_id', $referenceId)
                ->where('status', 'posted')
                ->get();

            $date ??= CarbonImmutable::now();
            $description ??= sprintf('Reversal of %s %s', $referenceType, $referenceId);

            $movements = [];
            foreach ($originals as $original) {
                $movements[] = $this->posting->post(new StockMovement(
                    warehouseId: $original->warehouse_id,
                    productId: $original->product_id,
                    quantity: $original->qty_base->negated(),
                    unitCost: $original->unit_cost,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    transactionDate: $date,
                    referenceLineId: $original->ref_line_id,
                    locationId: $original->location_id,
                    batchId: $original->batch_id,
                    ownerId: $original->owner_id,
                    actorId: $actor?->getKey(),
                    partnerId: $partnerId,
                    branchId: $branchId,
                    description: $description,
                    reversesLedgerId: $original->id,
                ));
            }

            foreach ($entries as $entry) {
                $entry->transitionTo('reversed', $actor);
            }

            return new StockReversalResult($movements, $entries->all());
        });
    }

    /**
     * @param  list<int>  $ledgerIds
     */
    private function assertNotAlreadyReversed(array $ledgerIds, string $referenceType, string $referenceId): void
    {
        $reversed = StockLedger::withoutGlobalScopes()
            ->whereIn('reverses_ledger_id', $ledgerIds)
            ->exists();

        if ($reversed) {
            throw new AlreadyReversed(sprintf(
                '%s %s has already been reversed.',
                $referenceType,
                $referenceId,
            ));
        }
    }
}
