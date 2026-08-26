<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\States\InvoiceExchangeStates;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Documents\DocumentStates;
use App\Support\Documents\HasDocumentState;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class InvoiceExchange extends DomainModel
{
    use HasDocumentState;

    protected $table = 'invoice_exchanges';

    public static function states(): DocumentStates
    {
        return new InvoiceExchangeStates;
    }

    protected function casts(): array
    {
        return [
            'exchange_date' => 'immutable_date',
            'promised_payment_date' => 'immutable_date',
            'total_amount' => DecimalCast::class.':2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    /**
     * Added by hand: `invoice_exchange_lines` is a pivot, and the generator
     * only derives relations from foreign keys (F-50).
     */
    public function salesInvoices(): BelongsToMany
    {
        return $this->belongsToMany(SalesInvoice::class, 'invoice_exchange_lines', 'exchange_id', 'sales_invoice_id');
    }

    public function invoiceExchangeLines(): HasMany
    {
        return $this->hasMany(InvoiceExchangeLine::class, 'exchange_id');
    }
}
