<?php

namespace App\Filament\Resources\Warehouses\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class WarehousesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('warehouse_type')->label('Type')->badge()->formatStateUsing(
                    fn (string $state): string => str_replace('_', ' ', $state),
                ),
                TextColumn::make('partner.name')->placeholder('—')->toggleable(),
                TextColumn::make('branch.name')->placeholder('—')->toggleable(),
                TextColumn::make('warehouse_locations_count')->counts('warehouseLocations')->label('Bays')->alignEnd(),
                IconColumn::make('active')->boolean(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('warehouse_type')->label('Type')->options([
                    'normal' => 'Normal', 'quarantine' => 'Quarantine', 'damaged' => 'Damaged goods',
                    'consignment_in' => 'Consignment in', 'consignment_out' => 'Consignment out',
                ]),
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
