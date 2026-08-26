<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RebateTier extends DomainModel
{
    protected $table = 'rebate_tiers';

    protected function casts(): array
    {
        return [
            'min_amount' => DecimalCast::class.':2',
            'rebate_amount' => DecimalCast::class.':2',
            'rebate_percent' => DecimalCast::class.':2',
            'min_qty' => DecimalCast::class.':4',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(RebateProgram::class, 'program_id');
    }
}
