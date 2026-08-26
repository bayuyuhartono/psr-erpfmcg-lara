<?php

namespace App\Domain\Masters\Models;

use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PriceList extends DomainModel
{
    protected $table = 'price_lists';

    public function priceListItems(): HasMany
    {
        return $this->hasMany(PriceListItem::class, 'price_list_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'price_list_id');
    }
}
