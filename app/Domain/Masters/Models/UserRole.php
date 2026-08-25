<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\PivotModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserRole extends PivotModel
{
    protected $table = 'user_roles';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
