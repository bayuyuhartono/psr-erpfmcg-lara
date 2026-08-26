<?php

namespace App\Filament\Resources\CustomerTiers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CustomerTiersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('priceList.name')->label('Price list')->placeholder('none — customers here cannot be priced'),
                TextColumn::make('business_partners_count')->counts('businessPartners')->label('Customers')->alignEnd(),
            ])
            ->defaultSort('name')
            ->filters([TrashedFilter::make()])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make(), ForceDeleteBulkAction::make(), RestoreBulkAction::make()]),
            ]);
    }
}
