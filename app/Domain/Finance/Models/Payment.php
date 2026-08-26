<?php

namespace App\Domain\Finance\Models;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Finance\States\PaymentStates;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Shared\Models\DomainModel;
use App\Models\User;
use App\Support\Documents\DocumentStates;
use App\Support\Documents\HasDocumentState;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Payment extends DomainModel
{
    use HasDocumentState;

    protected $table = 'payments';

    public static function states(): DocumentStates
    {
        return new PaymentStates;
    }

    protected function casts(): array
    {
        return [
            'payment_date' => 'immutable_date',
            'amount' => DecimalCast::class.':2',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'partner_id');
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'cash_account_id');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id');
    }

    public function postdatedCheques(): HasMany
    {
        return $this->hasMany(PostdatedCheque::class, 'payment_id');
    }
}
