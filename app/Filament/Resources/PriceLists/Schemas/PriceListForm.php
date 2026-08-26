<?php

namespace App\Filament\Resources\PriceLists\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PriceListForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            Toggle::make('active')->default(true),
            DatePicker::make('starts_on')->helperText('Blank means it has always applied.'),
            DatePicker::make('ends_on')->helperText('Blank means it does not expire.')
                ->after('starts_on'),
        ]);
    }
}
