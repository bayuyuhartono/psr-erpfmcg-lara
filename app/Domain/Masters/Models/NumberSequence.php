<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NumberSequence extends DomainModel
{
    protected $table = 'number_sequences';

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
