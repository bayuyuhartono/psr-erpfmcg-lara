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
use App\Support\ShelfLife\Exceptions\InvalidShelfLifeConfiguration;
use App\Support\Uom\Exceptions\BaseUnitLocked;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Product extends DomainModel
{
    protected $table = 'products';

    /**
     * The base unit is the only unit stock is stored in, so every quantity in
     * `stock_ledger`, `stock_balances` and `product_costs` is a count of it.
     * Changing it once movements exist does not convert that history — it
     * silently reinterprets it, turning 500 pieces into 500 cartons (F-01).
     *
     * Guarded on the model rather than in an action: the importer, the API and
     * Filament all have to obey it, and only one of them is easy to remember.
     */
    protected static function booted(): void
    {
        parent::booted();

        // A product whose batch and expiry flags disagree with its thresholds
        // passes every shelf-life check silently, which is the worst possible
        // outcome for a rule whose whole purpose is to block things (F-03).
        self::saving(function (self $product): void {
            if ($product->track_expiry && ! $product->track_batch) {
                throw new InvalidShelfLifeConfiguration(sprintf(
                    'Product %s tracks expiry but not batches. Expiry dates live on batches, so there would be nowhere to record one.',
                    $product->sku,
                ));
            }

            if ($product->min_life_on_receipt_pct !== null && $product->shelf_life_days === null) {
                throw new InvalidShelfLifeConfiguration(sprintf(
                    'Product %s sets a minimum life on receipt, which is a percentage of a shelf life it does not have.',
                    $product->sku,
                ));
            }

            if ($product->min_life_on_receipt_pct !== null
                && (BigDecimal::of($product->min_life_on_receipt_pct)->isNegative()
                    || BigDecimal::of($product->min_life_on_receipt_pct)->isGreaterThan(100))) {
                throw new InvalidShelfLifeConfiguration(sprintf(
                    'Minimum life on receipt for %s is %s%%; it is a percentage of the shelf life, so it falls between 0 and 100.',
                    $product->sku,
                    $product->min_life_on_receipt_pct,
                ));
            }

            if ($product->shelf_life_days !== null && (int) $product->shelf_life_days <= 0) {
                throw new InvalidShelfLifeConfiguration(sprintf('Shelf life for %s must be a positive number of days.', $product->sku));
            }

            if (! in_array($product->issue_method, ['fefo', 'fifo', 'any'], true)) {
                throw new InvalidShelfLifeConfiguration(sprintf(
                    'Issue method "%s" for %s is not one of fefo, fifo, any.',
                    $product->issue_method,
                    $product->sku,
                ));
            }
        });

        self::updating(function (self $product): void {
            if (! $product->isDirty('base_uom')) {
                return;
            }

            if ($product->stockLedger()->withoutGlobalScopes()->exists()) {
                throw new BaseUnitLocked(sprintf(
                    'Product %s has stock movement history, so its base unit stays "%s". Create a new product instead.',
                    $product->sku,
                    $product->getOriginal('base_uom'),
                ));
            }
        });
    }

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

    /**
     * Added by hand: `product_costs` is keyed by `product_id`, so there is at
     * most one row and the generated hasMany reads awkwardly everywhere.
     */
    public function cost(): HasOne
    {
        return $this->hasOne(ProductCost::class, 'product_id');
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
