<?php

namespace App\Domain\Masters\Services;

use App\Domain\Masters\Models\BusinessPartner;
use Illuminate\Support\Collection;

/**
 * As-you-type customer lookup for order entry.
 *
 * A distributor has two thousand outlets. Rendering all of them into a select
 * is a full table load on every keystroke of the screen they sit on, and it
 * gives a keyboard user two thousand options to arrow through.
 *
 * @see ProductSearch — the same shape, for the same reason
 */
final class CustomerSearch
{
    private const LIMIT = 8;

    /** @return Collection<int, BusinessPartner> */
    public function __invoke(string $term, int $limit = self::LIMIT): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        return BusinessPartner::query()
            ->customers()
            ->where('active', true)
            ->where(fn ($query) => $query
                ->where('code', 'like', $term.'%')
                ->orWhere('name', 'like', '%'.$term.'%'))
            ->orderByRaw('CASE WHEN code = ? THEN 0 WHEN code LIKE ? THEN 1 ELSE 2 END', [$term, $term.'%'])
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }
}
