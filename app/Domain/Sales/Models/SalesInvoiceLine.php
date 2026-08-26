<?php

namespace App\Domain\Sales\Models;

use App\Domain\Masters\Models\Product;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesInvoiceLine extends DomainModel
{
    protected $table = 'sales_invoice_lines';

    protected function casts(): array
    {
        return [
            'unit_price' => DecimalCast::class.':2',
            'discount_amount' => DecimalCast::class.':2',
            'line_total' => DecimalCast::class.':2',
            'qty' => DecimalCast::class.':4',
        ];
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function deliveryLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryLine::class, 'delivery_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
