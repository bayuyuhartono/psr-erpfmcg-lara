<?php

namespace App\Domain\Masters\Models;

use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Branch extends DomainModel
{
    protected $table = 'branches';

    public function numberSequences(): HasMany
    {
        return $this->hasMany(NumberSequence::class, 'branch_id');
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'branch_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'branch_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'branch_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'branch_id');
    }
}
