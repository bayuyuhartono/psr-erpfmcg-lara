<?php

namespace App\Domain\Sales\Models;

use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\WarehouseLocation;
use App\Domain\Masters\Models\Product;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DeliveryLine extends DomainModel
{
    protected function casts(): array
    {
        return [
            'qty_base' => DecimalCast::class.':4',
            'qty_accepted' => DecimalCast::class.':4',
            'unit_cost' => DecimalCast::class.':4',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'delivery_id');
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function salesInvoiceLines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class, 'delivery_line_id');
    }
}
