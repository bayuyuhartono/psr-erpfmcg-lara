<?php

namespace App\Filament\Resources\JournalEntries\Tables;

use App\Domain\Accounting\Models\JournalEntry;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class JournalEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_number')->label(__('Entry'))->searchable()->sortable(),
                TextColumn::make('entry_date')->date()->sortable(),
                TextColumn::make('ref_type')->label(__('Source'))->badge()->searchable(),
                TextColumn::make('description')->wrap()->placeholder(__('—')),
                TextColumn::make('journal_lines_sum_debit')->sum('journalLines', 'debit')->label(__('Amount'))->alignEnd(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'posted' => 'success', 'reversed' => 'danger', default => 'gray',
                }),
            ])
            ->defaultSort('entry_date', 'desc')
            ->filters([
                SelectFilter::make('status')->options(['draft' => 'Draft', 'posted' => 'Posted', 'reversed' => 'Reversed']),
                SelectFilter::make('ref_type')->label(__('Source'))->options(
                    fn (): array => JournalEntry::query()
                        ->distinct()->orderBy('ref_type')->pluck('ref_type', 'ref_type')->filter()->all(),
                ),
            ])
            // Entries are written by the posting kernel and never edited here.
            // Reading them is the point; a correction is a reversal.
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }
}
