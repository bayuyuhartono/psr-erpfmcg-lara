<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Consignment\Models\ConsignmentSettlement;
use App\Domain\Inventory\Exceptions\InvalidWarehouseType;
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
    public const TYPES = ['normal', 'quarantine', 'damaged', 'consignment_in', 'consignment_out'];

    /**
     * Types that exist to name someone else's side of an arrangement:
     * `consignment_in` holds a principal's goods in our warehouse,
     * `consignment_out` represents an outlet holding ours.
     */
    public const PARTNER_TYPES = ['consignment_in', 'consignment_out'];

    protected $table = 'warehouses';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        parent::booted();

        self::saving(function (self $warehouse): void {
            if (! in_array($warehouse->warehouse_type, self::TYPES, true)) {
                throw new InvalidWarehouseType(sprintf(
                    'Warehouse %s has type "%s", which is not one of %s.',
                    $warehouse->code,
                    $warehouse->warehouse_type,
                    implode(', ', self::TYPES),
                ));
            }

            $needsPartner = in_array($warehouse->warehouse_type, self::PARTNER_TYPES, true);

            // A consignment warehouse without its partner is stock nobody can
            // say the ownership of, which is the one thing consignment
            // modelling exists to keep straight.
            if ($needsPartner && $warehouse->partner_id === null) {
                throw new InvalidWarehouseType(sprintf(
                    'Warehouse %s is %s, so it needs the partner whose side of the arrangement it represents.',
                    $warehouse->code,
                    $warehouse->warehouse_type,
                ));
            }

            if (! $needsPartner && $warehouse->partner_id !== null) {
                throw new InvalidWarehouseType(sprintf(
                    'Warehouse %s is %s and holds our own goods, so it has no partner.',
                    $warehouse->code,
                    $warehouse->warehouse_type,
                ));
            }
        });
    }

    /** Goods here belong to the principal named by `partner_id`, not to us. */
    public function holdsConsignedStock(): bool
    {
        return $this->warehouse_type === 'consignment_in';
    }

    /** Our goods, placed with an outlet. Still ours until the settlement. */
    public function isOutletPlacement(): bool
    {
        return $this->warehouse_type === 'consignment_out';
    }

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
