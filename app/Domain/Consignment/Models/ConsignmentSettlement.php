<?php

namespace App\Domain\Consignment\Models;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Purchasing\Models\SupplierInvoice;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ConsignmentSettlement extends DomainModel
{
    protected function casts(): array
    {
        return [
            'total_amount' => DecimalCast::class.':2',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'partner_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function salesInvoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'settlement_id');
    }

    public function consignmentSettlementLines(): HasMany
    {
        return $this->hasMany(ConsignmentSettlementLine::class, 'settlement_id');
    }
}
