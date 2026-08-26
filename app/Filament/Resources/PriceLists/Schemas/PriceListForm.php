<?php

namespace App\Filament\Resources\PriceLists\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PriceListForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Price list')
                ->description('Prices themselves are added once the list is saved.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255)
                        ->placeholder('Wholesale 2026, Modern trade'),
                    Toggle::make('active')->default(true),
                ]),

            Section::make('When it applies')
                ->description('Leave both blank for a list that is simply always in force.')
                ->columns(2)
                ->schema([
                    DatePicker::make('starts_on')->helperText('Blank means it has always applied.'),
                    DatePicker::make('ends_on')->helperText('Blank means it does not expire.')
                        ->after('starts_on'),
                ]),
        ]);
    }
}
