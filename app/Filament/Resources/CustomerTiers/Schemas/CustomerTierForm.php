<?php

namespace App\Filament\Resources\CustomerTiers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CustomerTierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->unique(ignoreRecord: true)->maxLength(255)
                ->placeholder('Wholesale, Retail, Modern trade'),
            Select::make('price_list_id')
                ->label('Price list')
                ->relationship('priceList', 'name')
                ->searchable()->preload()
                ->helperText('Every customer on this tier is priced from here.'),
        ]);
    }
}
