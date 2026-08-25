<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Consignment\Models\ConsignmentSettlement;
use App\Domain\Masters\Models\Branch;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Models\SalesReturn;
use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Warehouse extends DomainModel
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'partner_id');
    }

    public function warehouseLocations(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class, 'warehouse_id');
    }

    public function stockLedger(): HasMany
    {
        return $this->hasMany(StockLedger::class, 'warehouse_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'warehouse_id');
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class, 'warehouse_id');
    }

    public function stockTransfersFromWarehouse(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'from_warehouse_id');
    }

    public function stockTransfersToWarehouse(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'to_warehouse_id');
    }

    public function stockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class, 'warehouse_id');
    }

    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class, 'warehouse_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'warehouse_id');
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'warehouse_id');
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class, 'warehouse_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'warehouse_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'warehouse_id');
    }

    public function salesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'warehouse_id');
    }

    public function consignmentSettlements(): HasMany
    {
        return $this->hasMany(ConsignmentSettlement::class, 'warehouse_id');
    }
}
