<?php

namespace App\Filament\Resources\PriceLists\RelationManagers;

use App\Domain\Masters\Models\PriceListItem;
use App\Filament\Forms\DecimalInput;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Prices, by unit and volume band.
 *
 * A product can appear several times: one row per unit, and one row per
 * quantity band within that unit. The band a quantity reaches decides the
 * price, so 0 / 10 / 50 means "under ten", "ten to forty-nine", "fifty up".
 */
class PriceListItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'priceListItems';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Prices');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('product_id')
                ->label(__('Product'))
                ->relationship('product', 'name')
                ->getOptionLabelFromRecordUsing(fn ($record): string => "{$record->sku} — {$record->name}")
                ->searchable(['sku', 'name'])
                ->preload()
                ->required(),
            TextInput::make('uom')->label(__('Unit'))->required()->maxLength(16),
            DecimalInput::quantity('min_qty')
                ->label(__('From quantity'))
                ->default(0)->required()
                ->helperText(__('0 is the base price. Add rows for volume bands.')),
            DecimalInput::money('unit_price')->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('uom')
            ->columns([
                TextColumn::make('product.sku')->label(__('SKU'))->searchable()->sortable(),
                TextColumn::make('product.name')->label(__('Product'))->searchable()->wrap(),
                TextColumn::make('uom')->label(__('Unit')),
                TextColumn::make('min_qty')->label(__('From qty'))->alignEnd(),
                TextColumn::make('unit_price')->numeric(decimalPlaces: 2)->alignEnd(),
                // Margin is price against moving average cost, so it is cost
                // data by another name and hides with it (F-70).
                TextColumn::make('margin')
                    ->label(__('Margin'))
                    ->alignEnd()
                    ->state(fn (PriceListItem $record): string => self::margin($record))
                    ->visible(fn (): bool => Gate::allows('view-cost')),
            ])
            ->defaultSort('product_id')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    /** Price against moving average cost, per base unit of the product. */
    private static function margin(PriceListItem $item): string
    {
        $cost = $item->product?->cost?->average_cost;

        if ($cost === null || $cost->isZero()) {
            return '—';
        }

        $price = BigDecimal::of($item->unit_price);

        return $price->minus($cost)
            ->multipliedBy(100)
            ->dividedBy($price->isZero() ? BigDecimal::one() : $price, 1, RoundingMode::HALF_UP)
            .'%';
    }
}
