<?php

namespace App\Filament\Resources\AccountingPeriods\Schemas;

use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A month, and whether it is still open.
 *
 * The status is not editable here on purpose. Closing a period runs checks —
 * unposted journals, stock that does not reconcile — and records who did it,
 * so it goes through `accounting:close` or the close action, never through a
 * dropdown.
 */
class AccountingPeriodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Period'))
                ->description(__('Closing runs checks and records who did it, so the status is not editable here.'))
                ->columns(2)
                ->schema([
                    TextInput::make('year')
                        ->numeric()->required()
                        ->minValue(2000)->maxValue(2100)
                        ->default(now()->year),
                    Select::make('month')
                        ->required()
                        ->default(now()->month)
                        ->options(collect(range(1, 12))->mapWithKeys(
                            fn (int $month): array => [$month => CarbonImmutable::create(null, $month)->format('F')],
                        )->all()),
                    TextInput::make('status')
                        ->disabled()
                        ->default('open')
                        ->helperText(__('Closed with `php artisan accounting:close`, which refuses if the books are not ready.')),
                ]),
        ]);
    }
}
