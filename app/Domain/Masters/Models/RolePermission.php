<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\PivotModel;
use App\Support\Money\DecimalCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RolePermission extends PivotModel
{
    protected $table = 'role_permissions';

    protected function casts(): array
    {
        return [
            'threshold' => DecimalCast::class.':2',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
