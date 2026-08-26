<?php

namespace App\Filament\Resources\PriceLists\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\PriceLists\PriceListResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPriceList extends EditRecord
{
    use ReportsDomainRules;

    protected static string $resource = PriceListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
