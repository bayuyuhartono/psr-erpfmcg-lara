<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PriceListItem extends DomainModel
{
    protected $table = 'price_list_items';

    protected function casts(): array
    {
        return [
            'unit_price' => DecimalCast::class.':2',
            'min_qty' => DecimalCast::class.':4',
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
