<?php

namespace App\Domain\Sales\Models;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Sales\States\SalesReturnStates;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Documents\DocumentStates;
use App\Support\Documents\HasDocumentState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesReturn extends DomainModel
{
    use HasDocumentState;

    protected $table = 'sales_returns';

    public static function states(): DocumentStates
    {
        return new SalesReturnStates;
    }

    protected function casts(): array
    {
        return [
            'resellable' => 'boolean',
            'return_date' => 'immutable_date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function salesReturnLines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class, 'sales_return_id');
    }
}
