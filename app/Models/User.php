<?php

namespace App\Models;

use App\Domain\Masters\Models\Branch;
use App\Domain\Masters\Models\Role;
use App\Support\Audit\AuditObserver;
use Brick\Math\BigDecimal;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['branch_id', 'email', 'password', 'full_name', 'active', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function getNameAttribute(): string
    {
        return $this->full_name;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function hasPermission(string $permission, BigDecimal|string|int|null $amount = null): bool
    {
        $grants = $this->roles()->with('rolePermissions')->get()
            ->flatMap->rolePermissions
            ->where('permission', $permission);

        if ($grants->isEmpty()) {
            return false;
        }

        if ($amount === null) {
            return true;
        }

        $requested = BigDecimal::of($amount);

        return $grants->contains(fn ($grant): bool => $grant->threshold === null || $requested->isLessThanOrEqualTo($grant->threshold)
        );
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->active && $this->roles()->exists();
    }

    protected static function booted(): void
    {
        static::observe(AuditObserver::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'active' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
