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
            Section::make(__('Identity'))->columns(2)->schema([
                TextInput::make('sku')->label(__('SKU'))->required()->unique(ignoreRecord: true)->maxLength(255),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('brand_id')->relationship('brand', 'name')->searchable()->preload(),
                Select::make('category_id')->relationship('category', 'name')->searchable()->preload(),
                // The server refuses this once movement history exists; the
                // form says so first rather than letting someone type it and
                // then be told no.
                TextInput::make('base_uom')
                    ->label(__('Base unit'))
                    ->required()
                    ->maxLength(16)
                    ->helperText(__('The only unit stock is stored in. Locked once the product has moved.'))
                    ->disabled(fn (?Product $record): bool => $record?->stockLedger()->withoutGlobalScopes()->exists() ?? false)
                    ->dehydrated(),
                Toggle::make('active')->default(true),
            ]),

            Section::make(__('Batch and expiry'))->columns(2)->schema([
                Toggle::make('track_batch')->label(__('Track batches'))->live(),
                Toggle::make('track_expiry')
                    ->label(__('Track expiry'))
                    ->live()
                    ->helperText(__('Expiry dates live on batches, so this needs batch tracking.'))
                    ->disabled(fn (callable $get): bool => ! $get('track_batch')),
                Select::make('issue_method')
                    ->options(['fefo' => 'FEFO — nearest expiry first', 'fifo' => 'FIFO — oldest first', 'any' => 'Any'])
                    ->default('fefo')
                    ->required(),
                TextInput::make('shelf_life_days')
                    ->label(__('Shelf life (days)'))
                    ->numeric()->minValue(1)
                    ->visible(fn (callable $get): bool => (bool) $get('track_expiry')),
                DecimalInput::percent('min_life_on_receipt_pct')
                    ->label(__('Minimum life on receipt (%)'))
                    ->helperText(__('Goods arriving below this share of shelf life are rejected outright.'))
                    ->visible(fn (callable $get): bool => (bool) $get('track_expiry')),
                TextInput::make('min_life_on_ship_days')
                    ->label(__('Minimum life on shipment (days)'))
                    ->numeric()->minValue(0)
                    ->helperText(__('Batches below this are never offered for picking.'))
                    ->visible(fn (callable $get): bool => (bool) $get('track_expiry')),
            ]),

            Section::make(__('Other'))->columns(3)->collapsed()->schema([
                DecimalInput::percent('vat_percent')->label(__('VAT %'))->helperText(__('Blank uses the company default.')),
                DecimalInput::quantity('reorder_point'),
                DecimalInput::make('weight_grams', 2)->label(__('Weight (g)')),
                DecimalInput::make('volume_cm3', 2)->label(__('Volume (cm³)')),
            ]),
        ]);
    }
}
