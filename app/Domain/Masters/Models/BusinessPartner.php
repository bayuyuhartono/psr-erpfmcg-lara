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
use App\Domain\Masters\Exceptions\InvalidPartnerRoles;
use App\Domain\Masters\Exceptions\PartnerRoleInUse;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class BusinessPartner extends DomainModel
{
    protected $table = 'business_partners';

    /** Which documents each role owns, and so what blocks removing it. */
    private const ROLE_USES = [
        'is_customer' => ['salesOrders', 'salesInvoices', 'deliveries', 'invoiceExchanges'],
        'is_supplier' => ['purchaseOrdersSupplier', 'goodsReceiptsSupplier', 'supplierInvoices'],
        'is_principal' => ['brands', 'distributionContracts', 'principalClaims', 'rebatePrograms'],
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => DecimalCast::class.':2',
            // Postgres int[] became JSON in the MySQL port. [1, 15] means
            // invoices are exchanged on the 1st and the 15th (F-50).
            'invoice_exchange_days' => 'array',
            'payment_days' => 'array',
            'is_supplier' => 'boolean',
            'is_customer' => 'boolean',
            'is_principal' => 'boolean',
            'on_hold' => 'boolean',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        self::saving(function (self $partner): void {
            if (! $partner->is_supplier && ! $partner->is_customer && ! $partner->is_principal) {
                throw new InvalidPartnerRoles(sprintf(
                    'Partner %s has no role. Supplier, customer or principal — a partner that is none of them appears in no list and can be used on no document.',
                    $partner->code,
                ));
            }

            foreach (['invoice_exchange_days', 'payment_days'] as $column) {
                self::assertDaysOfMonth($partner, $column);
            }
        });

        // Turning a role off does not delete the documents filed under it; it
        // hides the partner from every list those documents are reconciled
        // against. An unpaid invoice belonging to a partner who is no longer a
        // customer drops out of the aging report without a trace.
        self::updating(function (self $partner): void {
            foreach (self::ROLE_USES as $role => $relations) {
                if (! $partner->isDirty($role) || $partner->{$role}) {
                    continue;
                }

                foreach ($relations as $relation) {
                    if ($partner->{$relation}()->exists()) {
                        throw new PartnerRoleInUse(sprintf(
                            'Partner %s still has %s, so the %s role stays. Deactivate the partner instead.',
                            $partner->code,
                            Str::of($relation)->snake(' ')->toString(),
                            Str::of($role)->after('is_')->toString(),
                        ));
                    }
                }
            }
        });
    }

    /** @param  Builder<self>  $query */
    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('is_customer', true);
    }

    /** @param  Builder<self>  $query */
    public function scopeSuppliers(Builder $query): Builder
    {
        return $query->where('is_supplier', true);
    }

    /** @param  Builder<self>  $query */
    public function scopePrincipals(Builder $query): Builder
    {
        return $query->where('is_principal', true);
    }

    private static function assertDaysOfMonth(self $partner, string $column): void
    {
        $days = $partner->{$column};

        if ($days === null) {
            return;
        }

        foreach ((array) $days as $day) {
            if (! is_int($day) || $day < 1 || $day > 31) {
                throw new InvalidPartnerRoles(sprintf(
                    '%s for partner %s must be days of the month between 1 and 31, got "%s".',
                    Str::of($column)->snake(' ')->toString(),
                    $partner->code,
                    $day,
                ));
            }
        }
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
