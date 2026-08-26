<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Category')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                // Discount rules scoped to a parent reach every child, so the
                // tree is worth keeping shallow and meaningful.
                Select::make('parent_id')
                    ->label('Sits under')
                    ->relationship('parent', 'name', fn ($query, ?Model $record) => $record ? $query->whereKeyNot($record->getKey()) : $query)
                    ->searchable()->preload()
                    ->helperText('A discount on a parent category applies to everything below it.'),
            ]),
        ]);
    }
}
