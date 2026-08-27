<?php

namespace App\Filament\Resources\CustomerTiers;

use App\Domain\Masters\Models\CustomerTier;
use App\Filament\Resources\CustomerTiers\Pages\CreateCustomerTier;
use App\Filament\Resources\CustomerTiers\Pages\EditCustomerTier;
use App\Filament\Resources\CustomerTiers\Pages\ListCustomerTiers;
use App\Filament\Resources\CustomerTiers\Schemas\CustomerTierForm;
use App\Filament\Resources\CustomerTiers\Tables\CustomerTiersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CustomerTierResource extends Resource
{
    protected static ?string $model = CustomerTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?int $navigationSort = 20;

    public static function getPluralModelLabel(): string
    {
        return __('Customer tiers');
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerTierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerTiersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerTiers::route('/'),
            'create' => CreateCustomerTier::route('/create'),
            'edit' => EditCustomerTier::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
