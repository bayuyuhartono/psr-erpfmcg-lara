<?php

namespace App\Domain\Finance\Models;

use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class InvoiceExchange extends DomainModel
{
    protected function casts(): array
    {
        return [
            'total_amount' => DecimalCast::class.':2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    public function invoiceExchangeLines(): HasMany
    {
        return $this->hasMany(InvoiceExchangeLine::class, 'exchange_id');
    }
}
