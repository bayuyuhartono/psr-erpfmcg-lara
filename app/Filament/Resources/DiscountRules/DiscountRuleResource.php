<?php

namespace App\Filament\Resources\DiscountRules;

use App\Domain\Masters\Models\DiscountRule;
use App\Filament\Resources\DiscountRules\Pages\CreateDiscountRule;
use App\Filament\Resources\DiscountRules\Pages\EditDiscountRule;
use App\Filament\Resources\DiscountRules\Pages\ListDiscountRules;
use App\Filament\Resources\DiscountRules\Schemas\DiscountRuleForm;
use App\Filament\Resources\DiscountRules\Tables\DiscountRulesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DiscountRuleResource extends Resource
{
    protected static ?string $model = DiscountRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|\UnitEnum|null $navigationGroup = 'Pricing';

    protected static ?int $navigationSort = 20;

    protected static ?string $pluralModelLabel = 'Discount rules';

    public static function form(Schema $schema): Schema
    {
        return DiscountRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DiscountRulesTable::configure($table);
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
            'index' => ListDiscountRules::route('/'),
            'create' => CreateDiscountRule::route('/create'),
            'edit' => EditDiscountRule::route('/{record}/edit'),
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
