<?php

namespace App\Domain\Sales\Models;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\PartnerAddress;
use App\Domain\Sales\States\DeliveryStates;
use App\Domain\Shared\Models\DomainModel;
use App\Models\User;
use App\Support\Documents\DocumentStates;
use App\Support\Documents\HasDocumentState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Delivery extends DomainModel
{
    use HasDocumentState;

    protected $table = 'deliveries';

    public static function states(): DocumentStates
    {
        return new DeliveryStates;
    }

    protected function casts(): array
    {
        return [
            'delivery_date' => 'immutable_date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(PartnerAddress::class, 'shipping_address_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * Added by hand: `delivery_orders` is a pivot, and the generator only
     * derives relations from foreign keys — it produced the rows but not the
     * many-to-many they exist for (F-41).
     */
    public function salesOrders(): BelongsToMany
    {
        return $this->belongsToMany(SalesOrder::class, 'delivery_orders', 'delivery_id', 'sales_order_id');
    }

    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class, 'delivery_id');
    }

    public function deliveryLines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class, 'delivery_id');
    }

    public function deliveryPods(): HasMany
    {
        return $this->hasMany(DeliveryPod::class, 'delivery_id');
    }

    public function salesInvoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'delivery_id');
    }
}
