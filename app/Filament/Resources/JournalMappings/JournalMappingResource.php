<?php

namespace App\Filament\Resources\JournalMappings;

use App\Domain\Accounting\Models\JournalMapping;
use App\Filament\Resources\JournalMappings\Pages\CreateJournalMapping;
use App\Filament\Resources\JournalMappings\Pages\EditJournalMapping;
use App\Filament\Resources\JournalMappings\Pages\ListJournalMappings;
use App\Filament\Resources\JournalMappings\Schemas\JournalMappingForm;
use App\Filament\Resources\JournalMappings\Tables\JournalMappingsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class JournalMappingResource extends Resource
{
    protected static ?string $model = JournalMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 20;

    protected static ?string $pluralModelLabel = 'Journal mappings';

    public static function form(Schema $schema): Schema
    {
        return JournalMappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JournalMappingsTable::configure($table);
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
            'index' => ListJournalMappings::route('/'),
            'create' => CreateJournalMapping::route('/create'),
            'edit' => EditJournalMapping::route('/{record}/edit'),
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
