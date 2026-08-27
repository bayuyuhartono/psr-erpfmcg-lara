<?php

namespace App\Filament\Resources\Brands\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Brand'))->columns(2)->schema([
                TextInput::make('code')->required()->unique(ignoreRecord: true)->maxLength(64),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('principal_id')
                    ->label(__('Principal'))
                    ->relationship('principal', 'name', fn ($query) => $query->principals())
                    ->searchable()->preload()
                    ->helperText(__('Leave blank for unbranded or generic goods.')),
                Toggle::make('active')->default(true),
            ]),
        ]);
    }
}
