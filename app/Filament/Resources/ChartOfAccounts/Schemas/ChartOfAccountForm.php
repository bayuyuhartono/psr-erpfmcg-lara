<?php

namespace App\Filament\Resources\ChartOfAccounts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChartOfAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')->columns(2)->schema([
                TextInput::make('code')->required()->unique(ignoreRecord: true)->maxLength(32),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('account_type')
                    ->label('Type')
                    ->options([
                        'asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity',
                        'revenue' => 'Revenue', 'expense' => 'Expense',
                    ])
                    ->required()
                    ->helperText('Decides which side the account carries, and which statement it appears on.'),
                Select::make('parent_id')
                    ->label('Sits under')
                    ->relationship('parent', 'name')
                    ->searchable()->preload(),
            ]),

            Section::make('Behaviour')->columns(2)->schema([
                Toggle::make('is_cash_account')
                    ->label('Cash account')
                    ->helperText('Counted in the cash position an owner looks at first.'),
                Toggle::make('active')->default(true),
            ]),
        ]);
    }
}
