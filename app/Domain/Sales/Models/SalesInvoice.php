<?php

namespace App\Domain\Sales\Models;

use App\Domain\Consignment\Models\ConsignmentSettlement;
use App\Domain\Finance\Models\InvoiceExchangeLine;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Sales\States\SalesInvoiceStates;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Documents\DocumentStates;
use App\Support\Documents\HasDocumentState;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesInvoice extends DomainModel
{
    use HasDocumentState;

    protected $table = 'sales_invoices';

    public static function states(): DocumentStates
    {
        return new SalesInvoiceStates;
    }

    protected function casts(): array
    {
        return [
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
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
