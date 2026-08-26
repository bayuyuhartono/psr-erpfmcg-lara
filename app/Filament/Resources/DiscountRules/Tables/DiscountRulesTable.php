<?php

namespace App\Filament\Resources\DiscountRules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class DiscountRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sequence')->label('#')->alignEnd()->sortable(),
                TextColumn::make('name')->searchable()->wrap(),
                TextColumn::make('scope')->badge(),
                TextColumn::make('target')->badge(),
                TextColumn::make('percent')->suffix('%')->placeholder('—')->alignEnd(),
                TextColumn::make('amount')->numeric(decimalPlaces: 2)->placeholder('—')->alignEnd(),
                TextColumn::make('starts_on')->date()->placeholder('always')->toggleable(),
                TextColumn::make('ends_on')->date()->placeholder('open ended')->toggleable(),
            ])
            ->defaultSort('sequence')
            ->filters([
                SelectFilter::make('scope')->options([
                    'all' => 'Everything', 'product' => 'Product', 'brand' => 'Brand', 'category' => 'Category',
                ]),
                SelectFilter::make('target')->options([
                    'all' => 'Everyone', 'customer' => 'Customer', 'tier' => 'Tier', 'territory' => 'Territory',
                ]),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make(), ForceDeleteBulkAction::make(), RestoreBulkAction::make()]),
            ]);
    }
}
