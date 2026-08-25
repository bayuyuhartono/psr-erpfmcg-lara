<?php

namespace App\Domain\Sales\Models;

use App\Domain\Shared\Models\PivotModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeliveryOrder extends PivotModel
{
    protected $table = 'delivery_orders';

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'delivery_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
