<?php

namespace App\Filament\Resources\DiscountRules\Schemas;

use App\Domain\Masters\Models\Brand;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Category;
use App\Domain\Masters\Models\CustomerTier;
use App\Domain\Masters\Models\Product;
use App\Domain\Masters\Models\Territory;
use App\Filament\Forms\DecimalInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DiscountRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),
                TextInput::make('sequence')
                    ->numeric()->default(1)->required()
                    ->helperText(__('Discounts compound in this order: 5% then 3% leaves 92.15%, not 92%.')),
            ]),

            Section::make(__('What it applies to'))->columns(2)->schema([
                Select::make('scope')->options([
                    'all' => 'Everything', 'product' => 'One product', 'brand' => 'A brand', 'category' => 'A category',
                ])->default('all')->required()->live(),
                Select::make('scope_id')
                    ->label(__('Which'))
                    ->options(fn (callable $get): array => match ($get('scope')) {
                        'product' => Product::query()->pluck('name', 'id')->all(),
                        'brand' => Brand::query()->pluck('name', 'id')->all(),
                        'category' => Category::query()->pluck('name', 'id')->all(),
                        default => [],
                    })
                    ->searchable()
                    ->required(fn (callable $get): bool => $get('scope') !== 'all')
                    ->visible(fn (callable $get): bool => $get('scope') !== 'all')
                    ->helperText(fn (callable $get): ?string => $get('scope') === 'category'
                        ? 'Reaches every category below this one too.'
                        : null),
            ]),

            Section::make(__('Who gets it'))->columns(2)->schema([
                Select::make('target')->options([
                    'all' => 'Everyone', 'customer' => 'One customer', 'tier' => 'A tier', 'territory' => 'A territory',
                ])->default('all')->required()->live(),
                Select::make('target_id')
                    ->label(__('Which'))
                    ->options(fn (callable $get): array => match ($get('target')) {
                        'customer' => BusinessPartner::query()->customers()->pluck('name', 'id')->all(),
                        'tier' => CustomerTier::query()->pluck('name', 'id')->all(),
                        'territory' => Territory::query()->pluck('name', 'id')->all(),
                        default => [],
                    })
                    ->searchable()
                    ->required(fn (callable $get): bool => $get('target') !== 'all')
                    ->visible(fn (callable $get): bool => $get('target') !== 'all'),
            ]),

            Section::make(__('How much, and when'))->columns(2)->schema([
                DecimalInput::percent('percent')->label(__('Percent off')),
                DecimalInput::money('amount')->label(__('Amount off the line')),
                DecimalInput::quantity('min_qty')->label(__('From quantity')),
                DecimalInput::money('min_amount')->label(__('From line value')),
                DatePicker::make('starts_on'),
                DatePicker::make('ends_on')->after('starts_on'),
            ]),
        ]);
    }
}
