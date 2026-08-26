<?php

namespace App\Filament\Resources\PrintTemplates;

use App\Domain\Masters\Models\PrintTemplate;
use App\Filament\Resources\PrintTemplates\Pages\CreatePrintTemplate;
use App\Filament\Resources\PrintTemplates\Pages\EditPrintTemplate;
use App\Filament\Resources\PrintTemplates\Pages\ListPrintTemplates;
use App\Filament\Resources\PrintTemplates\Schemas\PrintTemplateForm;
use App\Filament\Resources\PrintTemplates\Tables\PrintTemplatesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PrintTemplateResource extends Resource
{
    protected static ?string $model = PrintTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static string|\UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 50;

    protected static ?string $pluralModelLabel = 'Print templates';

    public static function form(Schema $schema): Schema
    {
        return PrintTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrintTemplatesTable::configure($table);
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
            'index' => ListPrintTemplates::route('/'),
            'create' => CreatePrintTemplate::route('/create'),
            'edit' => EditPrintTemplate::route('/{record}/edit'),
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
