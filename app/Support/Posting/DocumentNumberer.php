<?php

namespace App\Support\Posting;

use App\Domain\Masters\Models\Branch;
use App\Domain\Masters\Models\NumberSequence;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class DocumentNumberer
{
    public function next(string $documentType, CarbonInterface $date, ?Branch $branch = null): string
    {
        return DB::transaction(function () use ($documentType, $date, $branch): string {
            $period = $date->format('Y-m');
            $branchId = $branch?->getKey();

            $sequence = $this->lockPeriod($documentType, $period, $branchId)
                ?? $this->openPeriod($documentType, $period, $branchId);

            $sequence->last_number++;
            $sequence->save();

            $prefix = strtr($sequence->prefix, [
                '{BRANCH}' => $branch?->code ?? '',
                '{YYYY}' => $date->format('Y'),
                '{YY}' => $date->format('y'),
                '{MM}' => $date->format('m'),
            ]);

            return $prefix.str_pad((string) $sequence->last_number, 6, '0', STR_PAD_LEFT);
        });
    }

    private function lockPeriod(string $documentType, string $period, ?string $branchId): ?NumberSequence
    {
        return $this->sequences($documentType, $branchId)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Roll the sequence into a new period, carrying the configured prefix
     * forward. Without this every module stops issuing numbers at midnight on
     * the 1st, on every installation, until someone inserts a row by hand.
     *
     * MySQL treats NULLs as distinct in a unique index, so the
     * (branch_id, document_type, period) key does not stop two branch-less
     * rollovers racing. Locking the previous period's row first serialises
     * them: the loser re-reads and finds the row the winner created.
     */
    private function openPeriod(string $documentType, string $period, ?string $branchId): NumberSequence
    {
        // Nearest configured period before this one, or — for a document
        // backdated before the sequence was ever configured — the earliest.
        // The prefix is the configuration; the period is only the reset bucket.
        $previous = $this->sequences($documentType, $branchId)
            ->where('period', '<', $period)
            ->orderByDesc('period')
            ->lockForUpdate()
            ->first()
            ?? $this->sequences($documentType, $branchId)
                ->orderBy('period')
                ->lockForUpdate()
                ->first();

        if (! $previous) {
            throw new RuntimeException("No number sequence configured for {$documentType}, {$period}.");
        }

        return $this->lockPeriod($documentType, $period, $branchId) ?? NumberSequence::create([
            'id' => (string) Str::uuid7(),
            'branch_id' => $branchId,
            'document_type' => $documentType,
            'prefix' => $previous->prefix,
            'period' => $period,
            'last_number' => 0,
        ]);
    }

    private function sequences(string $documentType, ?string $branchId): Builder
    {
        return NumberSequence::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('document_type', $documentType);
    }
}
