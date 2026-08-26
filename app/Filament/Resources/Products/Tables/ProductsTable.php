<?php

namespace App\Filament\Resources\Products\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->label('SKU')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable()->wrap(),
                TextColumn::make('brand.name')->searchable()->toggleable(),
                TextColumn::make('category.name')->toggleable(),
                TextColumn::make('base_uom')->label('Base unit'),
                IconColumn::make('track_batch')->label('Batch')->boolean()->toggleable(),
                IconColumn::make('track_expiry')->label('Expiry')->boolean()->toggleable(),
                IconColumn::make('active')->boolean(),
                // What we paid, and what we are holding. Hidden from anyone
                // without `product.view_cost` — which is every sales role
                // except the supervisor (F-70).
                TextColumn::make('cost.average_cost')
                    ->label('Avg cost')
                    ->alignEnd()
                    ->placeholder('—')
                    ->visible(fn (): bool => Gate::allows('view-cost')),
                TextColumn::make('cost.total_value')
                    ->label('Stock value')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable()
                    ->visible(fn (): bool => Gate::allows('view-cost')),
            ])
            ->defaultSort('sku')
            ->filters([
                SelectFilter::make('brand')->relationship('brand', 'name')->searchable()->preload(),
                TernaryFilter::make('active')->default(true),
                TernaryFilter::make('track_expiry')->label('Expiry tracked'),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
