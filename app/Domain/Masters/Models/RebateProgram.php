<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RebateProgram extends DomainModel
{
    protected $table = 'rebate_programs';

    public function principal(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'principal_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function rebateTiers(): HasMany
    {
        return $this->hasMany(RebateTier::class, 'program_id');
    }
}
