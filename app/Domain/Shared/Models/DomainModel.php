<?php

namespace App\Domain\Shared\Models;

use App\Support\Audit\AuditObserver;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

abstract class DomainModel extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $guarded = [];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected static function booted(): void
    {
        static::observe(AuditObserver::class);
    }

    protected static function newFactory(): ?Factory
    {
        $factory = 'Database\\Factories\\'.class_basename(static::class).'Factory';

        return class_exists($factory) ? $factory::new() : null;
    }
}
