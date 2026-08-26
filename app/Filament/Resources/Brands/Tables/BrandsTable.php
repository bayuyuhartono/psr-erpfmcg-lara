<?php

namespace App\Filament\Resources\Brands\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('principal.name')->placeholder('unbranded')->searchable(),
                TextColumn::make('products_count')->counts('products')->label('SKUs')->alignEnd(),
                IconColumn::make('active')->boolean(),
            ])
            ->defaultSort('name')
            ->filters([TrashedFilter::make()])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make(), ForceDeleteBulkAction::make(), RestoreBulkAction::make()]),
            ]);
    }
}
