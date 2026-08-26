<?php

namespace App\Filament\Resources\BusinessPartners\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class BusinessPartnersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable()->wrap(),
                IconColumn::make('is_customer')->label('Cust')->boolean(),
                IconColumn::make('is_supplier')->label('Supp')->boolean(),
                IconColumn::make('is_principal')->label('Prin')->boolean(),
                TextColumn::make('tier.name')->toggleable()->placeholder('—'),
                TextColumn::make('credit_limit')->numeric(decimalPlaces: 2)->alignEnd()->toggleable(),
                IconColumn::make('on_hold')->label('Hold')->boolean()->falseColor('gray'),
                IconColumn::make('active')->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_customer')->label('Customers'),
                TernaryFilter::make('is_supplier')->label('Suppliers'),
                TernaryFilter::make('is_principal')->label('Principals'),
                TernaryFilter::make('on_hold')->label('On hold'),
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
