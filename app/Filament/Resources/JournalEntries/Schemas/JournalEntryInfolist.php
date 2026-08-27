<?php

namespace App\Filament\Resources\JournalEntries\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A journal entry, read only.
 *
 * There is no create or edit page for these — the posting kernel writes them
 * and a correction is a reversal. What this screen is for is answering "why
 * does this account say that", so the lines matter more than the header.
 */
class JournalEntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('document_number')->label(__('Entry')),
                TextEntry::make('entry_date')->date(),
                TextEntry::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'posted' => 'success', 'reversed' => 'danger', default => 'gray',
                }),
                TextEntry::make('ref_type')
                    ->label(__('Raised by'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => str_replace('_', ' ', (string) $state)),
                TextEntry::make('description')->columnSpan(2)->placeholder(__('—')),
            ]),

            Section::make(__('Lines'))->schema([
                RepeatableEntry::make('journalLines')
                    ->hiddenLabel()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('account.code')->label(__('Account')),
                        TextEntry::make('account.name')->label(__(''))->color('gray'),
                        TextEntry::make('debit')
                            ->alignEnd()
                            ->formatStateUsing(fn ($state): string => $state === null || (float) (string) $state === 0.0
                                ? '—'
                                : number_format((float) (string) $state, 2, ',', '.')),
                        TextEntry::make('credit')
                            ->alignEnd()
                            ->formatStateUsing(fn ($state): string => $state === null || (float) (string) $state === 0.0
                                ? '—'
                                : number_format((float) (string) $state, 2, ',', '.')),
                    ]),
            ]),
        ]);
    }
}
