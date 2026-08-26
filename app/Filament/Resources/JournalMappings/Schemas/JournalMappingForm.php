<?php

namespace App\Filament\Resources\JournalMappings\Schemas;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalMapping;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * This screen is the whole reason no account code appears in posting logic.
 * A client with a different chart remaps here; nobody edits code.
 */
class JournalMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->description('Which account each kind of document posts to. Every client\'s chart is different, so this is configuration rather than code.')
                ->schema([
                    Select::make('document_type')
                        ->options(fn (): array => self::existing('document_type'))
                        ->searchable()->required()
                        ->helperText('The ledger reference type, e.g. goods_receipt.'),
                    Select::make('posting_key')
                        ->options(fn (): array => self::existing('posting_key'))
                        ->searchable()->required()
                        ->helperText('The role the account plays, e.g. inventory.'),
                    Select::make('account_id')
                        ->label('Account')
                        ->relationship('account', 'name')
                        ->getOptionLabelFromRecordUsing(fn (ChartOfAccount $record): string => "{$record->code} — {$record->name}")
                        ->searchable(['code', 'name'])->preload()->required(),
                ]),
        ]);
    }

    /** @return array<string, string> */
    private static function existing(string $column): array
    {
        return JournalMapping::query()
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }
}
