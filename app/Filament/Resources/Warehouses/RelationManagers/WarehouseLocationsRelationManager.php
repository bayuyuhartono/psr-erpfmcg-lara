<?php

namespace App\Filament\Resources\Warehouses\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Rack locations, listed in the order a picker walks them (F-40).
 */
class WarehouseLocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'warehouseLocations';

    protected static ?string $title = 'Rack locations';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label('Location')->required()->maxLength(32)->placeholder('A-01-03'),
            TextInput::make('pick_sequence')
                ->label('Pick order')
                ->numeric()
                ->helperText('The order staff walk the racks. Leave blank and this bay is visited last.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                TextColumn::make('pick_sequence')->label('Pick order')->placeholder('last')->alignEnd(),
                TextColumn::make('code')->label('Location')->searchable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->inPickOrder())
            ->defaultSort(null)
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
