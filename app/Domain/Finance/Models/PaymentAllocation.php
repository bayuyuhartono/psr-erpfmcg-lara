<?php

namespace App\Domain\Finance\Models;

use App\Domain\Purchasing\Models\SupplierInvoice;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentAllocation extends DomainModel
{
    protected function casts(): array
    {
        return [
            'amount' => DecimalCast::class.':2',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }
}
