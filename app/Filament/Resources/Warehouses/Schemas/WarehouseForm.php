<?php

namespace App\Filament\Resources\Warehouses\Schemas;

use App\Domain\Inventory\Models\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WarehouseForm
{
    private const TYPE_LABELS = [
        'normal' => 'Normal',
        'quarantine' => 'Quarantine',
        'damaged' => 'Damaged goods',
        'consignment_in' => 'Consignment in — a principal’s goods, held by us',
        'consignment_out' => 'Consignment out — our goods, held by an outlet',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')->columns(2)->schema([
                TextInput::make('code')->required()->unique(ignoreRecord: true)->maxLength(64),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('branch_id')->relationship('branch', 'name')->searchable()->preload(),
                Toggle::make('active')->default(true),
            ]),

            Section::make('What it holds')
                ->description('Consignment warehouses hold goods that are not ours, and are valued separately.')
                ->columns(2)
                ->schema([
                    Select::make('warehouse_type')
                        ->label('Type')
                        ->options(self::TYPE_LABELS)
                        ->default('normal')
                        ->required()
                        ->live(),
                    // Only the two consignment types have a partner, and they
                    // must have one — the server refuses either mistake.
                    Select::make('partner_id')
                        ->label('Partner')
                        ->relationship('partner', 'name')
                        ->searchable()
                        ->preload()
                        ->required(fn (callable $get): bool => in_array($get('warehouse_type'), Warehouse::PARTNER_TYPES, true))
                        ->visible(fn (callable $get): bool => in_array($get('warehouse_type'), Warehouse::PARTNER_TYPES, true))
                        ->helperText('Whose side of the arrangement this warehouse represents.'),
                ]),
        ]);
    }
}
