<?php

namespace App\Domain\Sales\Models;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\Branch;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\PartnerAddress;
use App\Domain\Masters\Models\PriceList;
use App\Domain\Masters\Models\Salesperson;
use App\Domain\Shared\Models\DomainModel;
use App\Models\User;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesOrder extends DomainModel
{
    protected $table = 'sales_orders';

    protected function casts(): array
    {
        return [
            'subtotal' => DecimalCast::class.':2',
            'discount_amount' => DecimalCast::class.':2',
            'vat_amount' => DecimalCast::class.':2',
            'total' => DecimalCast::class.':2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(PartnerAddress::class, 'shipping_address_id');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(Salesperson::class, 'salesperson_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function salesOrderLines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class, 'sales_order_id');
    }

    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class, 'sales_order_id');
    }
}
