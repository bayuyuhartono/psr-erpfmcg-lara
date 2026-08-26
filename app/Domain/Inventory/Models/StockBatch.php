<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Consignment\Models\ConsignmentSettlementLine;
use App\Domain\Masters\Models\Product;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PrincipalClaimLine;
use App\Domain\Purchasing\Models\PurchaseReturnLine;
use App\Domain\Sales\Models\DeliveryLine;
use App\Domain\Sales\Models\SalesReturnLine;
use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockBatch extends DomainModel
{
    protected $table = 'stock_batches';

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function stockLedger(): HasMany
    {
        return $this->hasMany(StockLedger::class, 'batch_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'batch_id');
    }

    public function stockTransferLines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'batch_id');
    }

    public function stockCountLines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'batch_id');
    }

    public function stockAdjustmentLines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'batch_id');
    }

    public function goodsReceiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'batch_id');
    }

    public function purchaseReturnLines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class, 'batch_id');
    }

    public function principalClaimLines(): HasMany
    {
        return $this->hasMany(PrincipalClaimLine::class, 'batch_id');
    }

    public function deliveryLines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class, 'batch_id');
    }

    public function salesReturnLines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class, 'batch_id');
    }

    public function consignmentSettlementLines(): HasMany
    {
        return $this->hasMany(ConsignmentSettlementLine::class, 'batch_id');
    }
}
