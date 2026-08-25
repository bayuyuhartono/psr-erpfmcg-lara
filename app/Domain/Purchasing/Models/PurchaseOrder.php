<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\Branch;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Shared\Models\DomainModel;
use App\Models\User;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PurchaseOrder extends DomainModel
{
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'supplier_id');
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'principal_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id');
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'purchase_order_id');
    }
}
