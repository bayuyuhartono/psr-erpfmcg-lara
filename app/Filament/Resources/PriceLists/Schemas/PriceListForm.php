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
            Section::make(__('Price list'))
                ->description(__('Prices themselves are added once the list is saved.'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255)
                        ->placeholder(__('Wholesale 2026, Modern trade')),
                    Toggle::make('active')->default(true),
                ]),

            Section::make(__('When it applies'))
                ->description(__('Leave both blank for a list that is simply always in force.'))
                ->columns(2)
                ->schema([
                    DatePicker::make('starts_on')->helperText(__('Blank means it has always applied.')),
                    DatePicker::make('ends_on')->helperText(__('Blank means it does not expire.'))
                        ->after('starts_on'),
                ]),
        ]);
    }
}
