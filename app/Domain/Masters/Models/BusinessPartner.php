<?php

namespace App\Domain\Masters\Models;

use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Consignment\Models\ConsignmentSettlement;
use App\Domain\Finance\Models\InvoiceExchange;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PostdatedCheque;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PrincipalClaim;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\SupplierInvoice;
use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Models\SalesReturn;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BusinessPartner extends DomainModel
{
    protected function casts(): array
    {
        return [
            'credit_limit' => DecimalCast::class.':2',
        ];
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(CustomerTier::class, 'tier_id');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(Salesperson::class, 'salesperson_id');
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function partnerAddresses(): HasMany
    {
        return $this->hasMany(PartnerAddress::class, 'partner_id');
    }

    public function distributionContracts(): HasMany
    {
        return $this->hasMany(DistributionContract::class, 'principal_id');
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class, 'principal_id');
    }

    public function rebatePrograms(): HasMany
    {
        return $this->hasMany(RebateProgram::class, 'principal_id');
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'partner_id');
    }

    public function stockLedger(): HasMany
    {
        return $this->hasMany(StockLedger::class, 'owner_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'owner_id');
    }

    public function purchaseOrdersSupplier(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }

    public function purchaseOrdersPrincipal(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'principal_id');
    }

    public function goodsReceiptsSupplier(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'supplier_id');
    }

    public function goodsReceiptsOwner(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'owner_id');
    }

    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class, 'supplier_id');
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class, 'supplier_id');
    }

    public function principalClaims(): HasMany
    {
        return $this->hasMany(PrincipalClaim::class, 'principal_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'customer_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'customer_id');
    }

    public function salesInvoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'customer_id');
    }

    public function salesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'customer_id');
    }

    public function consignmentSettlements(): HasMany
    {
        return $this->hasMany(ConsignmentSettlement::class, 'partner_id');
    }

    public function invoiceExchanges(): HasMany
    {
        return $this->hasMany(InvoiceExchange::class, 'customer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'partner_id');
    }

    public function postdatedCheques(): HasMany
    {
        return $this->hasMany(PostdatedCheque::class, 'partner_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'partner_id');
    }
}
