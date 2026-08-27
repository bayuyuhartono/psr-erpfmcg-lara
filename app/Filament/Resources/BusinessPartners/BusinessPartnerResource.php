<?php

namespace App\Filament\Resources\BusinessPartners;

use App\Domain\Masters\Models\BusinessPartner;
use App\Filament\Resources\BusinessPartners\Pages\CreateBusinessPartner;
use App\Filament\Resources\BusinessPartners\Pages\EditBusinessPartner;
use App\Filament\Resources\BusinessPartners\Pages\ListBusinessPartners;
use App\Filament\Resources\BusinessPartners\Schemas\BusinessPartnerForm;
use App\Filament\Resources\BusinessPartners\Tables\BusinessPartnersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BusinessPartnerResource extends Resource
{
    protected static ?string $model = BusinessPartner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?int $navigationSort = 10;

    public static function getPluralModelLabel(): string
    {
        return __('Business partners');
    }

    public static function form(Schema $schema): Schema
    {
        return BusinessPartnerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BusinessPartnersTable::configure($table);
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
            'index' => ListBusinessPartners::route('/'),
            'create' => CreateBusinessPartner::route('/create'),
            'edit' => EditBusinessPartner::route('/{record}/edit'),
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
