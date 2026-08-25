<?php

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class PlainModel extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected static function newFactory(): ?Factory
    {
        $factory = 'Database\\Factories\\'.class_basename(static::class).'Factory';

        return class_exists($factory) ? $factory::new() : null;
    }
}
