<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\States\PostdatedChequeStates;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Shared\Models\DomainModel;
use App\Support\Documents\DocumentStates;
use App\Support\Documents\HasDocumentState;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PostdatedCheque extends DomainModel
{
    use HasDocumentState;

    protected $table = 'postdated_cheques';

    public static function states(): DocumentStates
    {
        return new PostdatedChequeStates;
    }

    protected function casts(): array
    {
        return [
            'received_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'resolved_on' => 'immutable_date',
            'amount' => DecimalCast::class.':2',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'partner_id');
    }
}
