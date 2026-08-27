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
            Section::make(__('Account'))->columns(2)->schema([
                TextInput::make('code')->required()->unique(ignoreRecord: true)->maxLength(32),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('account_type')
                    ->label(__('Type'))
                    ->options([
                        'asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity',
                        'revenue' => 'Revenue', 'expense' => 'Expense',
                    ])
                    ->required()
                    ->helperText(__('Decides which side the account carries, and which statement it appears on.')),
                Select::make('parent_id')
                    ->label(__('Sits under'))
                    ->relationship('parent', 'name')
                    ->searchable()->preload(),
            ]),

            Section::make(__('Behaviour'))->columns(2)->schema([
                Toggle::make('is_cash_account')
                    ->label(__('Cash account'))
                    ->helperText(__('Counted in the cash position an owner looks at first.')),
                Toggle::make('active')->default(true),
            ]),
        ]);
    }
}
