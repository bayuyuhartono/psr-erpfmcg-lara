<?php

namespace App\Filament\Resources\BusinessPartners\Schemas;

use App\Filament\Forms\DecimalInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BusinessPartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')->columns(2)->schema([
                TextInput::make('code')->required()->unique(ignoreRecord: true)->maxLength(64),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('tax_id')->label('NPWP')->maxLength(64),
                TextInput::make('contact_person')->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(64),
                Toggle::make('active')->default(true),
            ]),

            // One company is often several things at once — a principal that
            // also buys back damaged stock, a supplier that is also an outlet.
            Section::make('Roles')->columns(3)->schema([
                Toggle::make('is_customer')->label('Customer')->live(),
                Toggle::make('is_supplier')->label('Supplier'),
                Toggle::make('is_principal')->label('Principal'),
            ])->description('A partner needs at least one. A role in use by existing documents cannot be taken away.'),

            Section::make('Customer terms')
                ->columns(2)
                ->visible(fn (callable $get): bool => (bool) $get('is_customer'))
                ->schema([
                    Select::make('tier_id')->label('Tier')->relationship('tier', 'name')->searchable()->preload()
                        ->helperText('Decides which price list applies.'),
                    Select::make('salesperson_id')->relationship('salesperson', 'name')->searchable()->preload(),
                    Select::make('territory_id')->relationship('territory', 'name')->searchable()->preload(),
                    DecimalInput::money('credit_limit')->default(0),
                    TextInput::make('payment_terms_days')->label('Payment terms (days)')->numeric()->default(0),
                    TagsInput::make('invoice_exchange_days')
                        ->label('Invoice exchange days')
                        ->placeholder('1')
                        ->helperText('Days of the month invoices are handed over, e.g. 1 and 15.'),
                    TagsInput::make('payment_days')
                        ->label('Payment days')
                        ->helperText('Days of the month the outlet pays.'),
                    Toggle::make('on_hold')->label('On hold')->live(),
                    Textarea::make('hold_reason')->visible(fn (callable $get): bool => (bool) $get('on_hold'))->columnSpanFull(),
                ]),
        ]);
    }
}
