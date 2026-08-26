<?php

namespace App\Domain\Masters\Services;

use App\Domain\Masters\Models\Product;
use Illuminate\Support\Collection;

/**
 * As-you-type product lookup for order entry (F-31, F-02 §7.4).
 *
 * Matches code, name and barcode in one query, because a sales admin types
 * whichever they have in front of them — the SKU from a price list, the name
 * from a phone call, the barcode from a carton.
 *
 * Ordered so the obvious answer is first: an exact code match, then codes
 * starting with what was typed, then everything else. A search that puts the
 * right product third costs more keystrokes than one that is slightly slower.
 */
final class ProductSearch
{
    private const LIMIT = 20;

    /** @return Collection<int, Product> */
    public function __invoke(string $term, int $limit = self::LIMIT): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        $prefix = $term.'%';
        $anywhere = '%'.$term.'%';

        return Product::query()
            ->where('active', true)
            ->where(fn ($query) => $query
                ->where('sku', 'like', $prefix)
                ->orWhere('name', 'like', $anywhere)
                ->orWhereHas('productUoms', fn ($uoms) => $uoms->where('barcode', $term)))
            ->orderByRaw('CASE WHEN sku = ? THEN 0 WHEN sku LIKE ? THEN 1 ELSE 2 END', [$term, $prefix])
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }
}
