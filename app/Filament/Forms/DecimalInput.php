<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\TextInput;

/**
 * A numeric input for a column that is `BigDecimal` in PHP.
 *
 * Filament's `->numeric()` attaches a state cast that runs `floatval()` on the
 * value, which throws on a `BigDecimal` and would put every money and quantity
 * field back into float arithmetic if it did not. These inputs keep the value
 * a string end to end — validated as numeric, typed on a numeric keypad, and
 * handed to the model as a string that `DecimalCast` turns back into an exact
 * decimal.
 *
 * Use these for anything the migration declared with the `money()`, `qty()`,
 * `cost()` or `percent()` macros. Plain `TextInput::make()->numeric()` is for
 * genuine integers — days, sequences, counts.
 */
final class DecimalInput
{
    public static function money(string $name): TextInput
    {
        return self::make($name, scale: 2)->prefix('Rp');
    }

    public static function quantity(string $name): TextInput
    {
        return self::make($name, scale: 4);
    }

    public static function cost(string $name): TextInput
    {
        return self::make($name, scale: 4)->prefix('Rp');
    }

    public static function percent(string $name): TextInput
    {
        return self::make($name, scale: 2)->suffix('%')->rules(['numeric', 'min:0', 'max:100']);
    }

    public static function make(string $name, int $scale): TextInput
    {
        return TextInput::make($name)
            ->rule('numeric')
            ->inputMode('decimal')
            ->step(1 / (10 ** $scale))
            ->formatStateUsing(fn (mixed $state): ?string => $state === null ? null : (string) $state);
    }
}
