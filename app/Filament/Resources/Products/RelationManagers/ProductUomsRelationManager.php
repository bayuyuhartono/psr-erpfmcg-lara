<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Domain\Masters\Actions\AddProductUom;
use App\Domain\Masters\Models\Product;
use App\Domain\Masters\Models\ProductUom;
use App\Filament\Forms\DecimalInput;
use DomainException;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The units a product is bought and sold in.
 *
 * Both the create and edit actions hand off to `AddProductUom` rather than
 * writing the row themselves. That action is where "the base unit contains one
 * of itself" and "only one default each way" live, and the importer and API
 * call the same one — a form that saved directly would be a second, quietly
 * different set of rules.
 */
class ProductUomsRelationManager extends RelationManager
{
    protected static string $relationship = 'productUoms';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Units');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('uom')
                ->label(__('Unit'))
                ->required()
                ->maxLength(16)
                ->helperText(fn (): string => 'e.g. CARTON, PACK. The base unit is '.$this->getOwnerRecord()->base_uom.'.'),
            DecimalInput::quantity('qty_in_base')
                ->label(fn (): string => 'How many '.$this->getOwnerRecord()->base_uom.' in one')
                ->required(),
            TextInput::make('barcode')->maxLength(64),
            Toggle::make('default_for_purchase')->label(__('Default when buying')),
            Toggle::make('default_for_sale')->label(__('Default when selling')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('uom')
            ->columns([
                TextColumn::make('uom')->label(__('Unit'))->searchable(),
                TextColumn::make('qty_in_base')->label(__('In base units')),
                TextColumn::make('barcode')->searchable()->placeholder(__('—')),
                IconColumn::make('default_for_purchase')->label(__('Buy'))->boolean(),
                IconColumn::make('default_for_sale')->label(__('Sell'))->boolean(),
            ])
            ->defaultSort('qty_in_base', 'desc')
            ->headerActions([
                CreateAction::make()->using(fn (array $data): Model => $this->addUnit($data)),
            ])
            ->recordActions([
                EditAction::make()->using(fn (ProductUom $record, array $data): Model => $this->addUnit($data + ['uom' => $record->uom])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    /** @param  array<string, mixed>  $data */
    private function addUnit(array $data): Model
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        try {
            return app(AddProductUom::class)(
                $product,
                $data['uom'],
                $data['qty_in_base'],
                $data['barcode'] ?? null,
                (bool) ($data['default_for_purchase'] ?? false),
                (bool) ($data['default_for_sale'] ?? false),
            );
        } catch (DomainException $exception) {
            Notification::make()->title('That is not allowed')->body($exception->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }
}
