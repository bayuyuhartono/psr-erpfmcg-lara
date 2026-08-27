<?php

namespace App\Filament\Resources\PriceLists\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PriceListsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('starts_on')->date()->placeholder(__('always')),
                TextColumn::make('ends_on')->date()->placeholder(__('open ended')),
                TextColumn::make('price_list_items_count')->counts('priceListItems')->label(__('Prices'))->alignEnd(),
                TextColumn::make('customer_tiers_count')->counts('customerTiers')->label(__('Tiers'))->alignEnd(),
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
