<?php

namespace App\Filament\Resources\AccountingPeriods;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Filament\Resources\AccountingPeriods\Pages\CreateAccountingPeriod;
use App\Filament\Resources\AccountingPeriods\Pages\EditAccountingPeriod;
use App\Filament\Resources\AccountingPeriods\Pages\ListAccountingPeriods;
use App\Filament\Resources\AccountingPeriods\Schemas\AccountingPeriodForm;
use App\Filament\Resources\AccountingPeriods\Tables\AccountingPeriodsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AccountingPeriodResource extends Resource
{
    protected static ?string $model = AccountingPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|\UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 40;

    protected static ?string $pluralModelLabel = 'Periods';

    public static function form(Schema $schema): Schema
    {
        return AccountingPeriodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountingPeriodsTable::configure($table);
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
            'index' => ListAccountingPeriods::route('/'),
            'create' => CreateAccountingPeriod::route('/create'),
            'edit' => EditAccountingPeriod::route('/{record}/edit'),
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
