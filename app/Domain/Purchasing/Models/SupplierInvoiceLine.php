<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Masters\Models\Product;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupplierInvoiceLine extends DomainModel
{
    protected function casts(): array
    {
        return [
            'unit_price' => DecimalCast::class.':2',
            'line_total' => DecimalCast::class.':2',
            'qty' => DecimalCast::class.':4',
        ];
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
