<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

final class DecimalCast implements CastsAttributes
{
    public function __construct(private readonly int $scale = 2) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?BigDecimal
    {
        return $value === null ? null : BigDecimal::of((string) $value)->toScale($this->scale, RoundingMode::HALF_UP);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) BigDecimal::of($value)->toScale($this->scale, RoundingMode::HALF_UP);
    }
}
