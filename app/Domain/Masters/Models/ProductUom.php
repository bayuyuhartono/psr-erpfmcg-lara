<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductUom extends DomainModel
{
    protected $table = 'product_uoms';

    protected function casts(): array
    {
        return [
            'qty_in_base' => DecimalCast::class.':4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
