<?php

namespace App\Domain\Sales\Models;

use App\Domain\Consignment\Models\ConsignmentSettlement;
use App\Domain\Finance\Models\InvoiceExchangeLine;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesInvoice extends DomainModel
{
    protected $table = 'sales_invoices';

    protected function casts(): array
    {
        return [
            'subtotal' => DecimalCast::class.':2',
            'discount_amount' => DecimalCast::class.':2',
            'vat_amount' => DecimalCast::class.':2',
            'total' => DecimalCast::class.':2',
            'paid_amount' => DecimalCast::class.':2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'delivery_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ConsignmentSettlement::class, 'settlement_id');
    }

    public function salesInvoiceLines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class, 'sales_invoice_id');
    }

    public function salesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'sales_invoice_id');
    }

    public function consignmentSettlements(): HasMany
    {
        return $this->hasMany(ConsignmentSettlement::class, 'sales_invoice_id');
    }

    public function invoiceExchangeLines(): HasMany
    {
        return $this->hasMany(InvoiceExchangeLine::class, 'sales_invoice_id');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'sales_invoice_id');
    }
}
