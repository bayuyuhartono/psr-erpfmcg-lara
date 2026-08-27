<?php

namespace App\Filament\Resources\CustomerTiers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerTierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Tier'))->columns(2)->schema([
                TextInput::make('name')->required()->unique(ignoreRecord: true)->maxLength(255)
                    ->placeholder(__('Wholesale, Retail, Modern trade')),
                Select::make('price_list_id')
                    ->label(__('Price list'))
                    ->relationship('priceList', 'name')
                    ->searchable()->preload()
                    ->helperText(__('Every customer on this tier is priced from here.')),
            ]),
        ]);
    }
}
