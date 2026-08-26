<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Domain\Masters\Models\Product;
use App\Filament\Forms\DecimalInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')->columns(2)->schema([
                TextInput::make('sku')->label('SKU')->required()->unique(ignoreRecord: true)->maxLength(255),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('brand_id')->relationship('brand', 'name')->searchable()->preload(),
                Select::make('category_id')->relationship('category', 'name')->searchable()->preload(),
                // The server refuses this once movement history exists; the
                // form says so first rather than letting someone type it and
                // then be told no.
                TextInput::make('base_uom')
                    ->label('Base unit')
                    ->required()
                    ->maxLength(16)
                    ->helperText('The only unit stock is stored in. Locked once the product has moved.')
                    ->disabled(fn (?Product $record): bool => $record?->stockLedger()->withoutGlobalScopes()->exists() ?? false)
                    ->dehydrated(),
                Toggle::make('active')->default(true),
            ]),

            Section::make('Batch and expiry')->columns(2)->schema([
                Toggle::make('track_batch')->label('Track batches')->live(),
                Toggle::make('track_expiry')
                    ->label('Track expiry')
                    ->live()
                    ->helperText('Expiry dates live on batches, so this needs batch tracking.')
                    ->disabled(fn (callable $get): bool => ! $get('track_batch')),
                Select::make('issue_method')
                    ->options(['fefo' => 'FEFO — nearest expiry first', 'fifo' => 'FIFO — oldest first', 'any' => 'Any'])
                    ->default('fefo')
                    ->required(),
                TextInput::make('shelf_life_days')
                    ->label('Shelf life (days)')
                    ->numeric()->minValue(1)
                    ->visible(fn (callable $get): bool => (bool) $get('track_expiry')),
                DecimalInput::percent('min_life_on_receipt_pct')
                    ->label('Minimum life on receipt (%)')
                    ->helperText('Goods arriving below this share of shelf life are rejected outright.')
                    ->visible(fn (callable $get): bool => (bool) $get('track_expiry')),
                TextInput::make('min_life_on_ship_days')
                    ->label('Minimum life on shipment (days)')
                    ->numeric()->minValue(0)
                    ->helperText('Batches below this are never offered for picking.')
                    ->visible(fn (callable $get): bool => (bool) $get('track_expiry')),
            ]),

            Section::make('Other')->columns(3)->collapsed()->schema([
                DecimalInput::percent('vat_percent')->label('VAT %')->helperText('Blank uses the company default.'),
                DecimalInput::quantity('reorder_point'),
                DecimalInput::make('weight_grams', 2)->label('Weight (g)'),
                DecimalInput::make('volume_cm3', 2)->label('Volume (cm³)'),
            ]),
        ]);
    }
}
