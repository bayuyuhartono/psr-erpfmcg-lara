<?php

namespace App\Filament\Resources\ChartOfAccounts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ChartOfAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('account_type')->label(__('Type'))->badge(),
                TextColumn::make('parent.name')->label(__('Sits under'))->placeholder(__('—'))->toggleable(),
                TextColumn::make('journal_mappings_count')
                    ->counts('journalMappings')
                    ->label(__('Mapped to'))
                    ->alignEnd()
                    ->tooltip('How many document types post here'),
                IconColumn::make('is_cash_account')->label(__('Cash'))->boolean()->falseColor('gray'),
                IconColumn::make('active')->boolean(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('account_type')->label(__('Type'))->options([
                    'asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity',
                    'revenue' => 'Revenue', 'expense' => 'Expense',
                ]),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
