<?php

namespace App\Filament\Resources\JournalMappings\Tables;

use App\Domain\Accounting\Models\JournalMapping;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class JournalMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_type')->badge()->searchable()->sortable(),
                TextColumn::make('posting_key')->label(__('Posts as'))->searchable(),
                TextColumn::make('account.code')->label(__('Account'))->sortable(),
                TextColumn::make('account.name')->label(__(''))->color('gray'),
            ])
            ->defaultSort('document_type')
            ->groups(['document_type'])
            ->filters([
                SelectFilter::make('document_type')->options(
                    fn (): array => JournalMapping::query()
                        ->distinct()->orderBy('document_type')->pluck('document_type', 'document_type')->all(),
                ),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
