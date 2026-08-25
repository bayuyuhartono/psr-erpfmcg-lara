<?php

namespace App\Domain\Finance\Models;

use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Shared\Models\PivotModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InvoiceExchangeLine extends PivotModel
{
    protected $table = 'invoice_exchange_lines';

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(InvoiceExchange::class, 'exchange_id');
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
