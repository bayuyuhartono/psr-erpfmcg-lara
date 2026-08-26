<?php

namespace App\Domain\Masters\Models;

use App\Domain\Consignment\Models\ConsignmentSettlementLine;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockAdjustmentLine;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockCountLine;
use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PrincipalClaimLine;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Domain\Purchasing\Models\PurchaseReturnLine;
use App\Domain\Purchasing\Models\SupplierInvoiceLine;
use App\Domain\Sales\Models\DeliveryLine;
use App\Domain\Sales\Models\SalesInvoiceLine;
use App\Domain\Sales\Models\SalesOrderLine;
use App\Domain\Sales\Models\SalesReturnLine;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Product extends DomainModel
{
    protected $table = 'products';

    protected function casts(): array
    {
        return [
            'min_life_on_receipt_pct' => DecimalCast::class.':2',
            'vat_percent' => DecimalCast::class.':2',
            'reorder_point' => DecimalCast::class.':4',
            'weight_grams' => DecimalCast::class.':2',
            'volume_cm3' => DecimalCast::class.':2',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function productUoms(): HasMany
    {
        return $this->hasMany(ProductUom::class, 'product_id');
    }

    public function priceListItems(): HasMany
    {
        return $this->hasMany(PriceListItem::class, 'product_id');
    }

    public function promotionsBuyProduct(): HasMany
    {
        return $this->hasMany(Promotion::class, 'buy_product_id');
    }

    public function promotionsGetProduct(): HasMany
    {
        return $this->hasMany(Promotion::class, 'get_product_id');
    }

    public function stockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class, 'product_id');
    }

    public function stockLedger(): HasMany
    {
        return $this->hasMany(StockLedger::class, 'product_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'product_id');
    }

    public function productCosts(): HasMany
    {
        return $this->hasMany(ProductCost::class, 'product_id');
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class, 'product_id');
    }

    public function stockTransferLines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'product_id');
    }

    public function stockCountLines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'product_id');
    }

    public function stockAdjustmentLines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'product_id');
    }

    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'product_id');
    }

    public function goodsReceiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'product_id');
    }

    public function supplierInvoiceLines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class, 'product_id');
    }

    public function purchaseReturnLines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class, 'product_id');
    }

    public function principalClaimLines(): HasMany
    {
        return $this->hasMany(PrincipalClaimLine::class, 'product_id');
    }

    public function salesOrderLines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class, 'product_id');
    }

    public function deliveryLines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class, 'product_id');
    }

    public function salesInvoiceLines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class, 'product_id');
    }

    public function salesReturnLines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class, 'product_id');
    }

    public function consignmentSettlementLines(): HasMany
    {
        return $this->hasMany(ConsignmentSettlementLine::class, 'product_id');
    }
}
