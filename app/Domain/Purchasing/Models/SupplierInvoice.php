<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Consignment\Models\ConsignmentSettlement;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SupplierInvoice extends DomainModel
{
    protected function casts(): array
    {
        return [
            'subtotal' => DecimalCast::class.':2',
            'vat_amount' => DecimalCast::class.':2',
            'total' => DecimalCast::class.':2',
            'paid_amount' => DecimalCast::class.':2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'supplier_id');
    }

    public function supplierInvoiceLines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class, 'supplier_invoice_id');
    }

    public function consignmentSettlements(): HasMany
    {
        return $this->hasMany(ConsignmentSettlement::class, 'supplier_invoice_id');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'supplier_invoice_id');
    }
}
