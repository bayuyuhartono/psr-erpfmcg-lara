<?php

namespace App\Support\Posting;

use App\Domain\Masters\Models\Branch;
use App\Domain\Masters\Models\NumberSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DocumentNumberer
{
    public function next(string $documentType, CarbonInterface $date, ?Branch $branch = null): string
    {
        return DB::transaction(function () use ($documentType, $date, $branch): string {
            $period = $date->format('Y-m');
            $sequence = NumberSequence::withoutGlobalScopes()
                ->where('branch_id', $branch?->getKey())
                ->where('document_type', $documentType)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                throw new RuntimeException("No number sequence configured for {$documentType}, {$period}.");
            }

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
}
