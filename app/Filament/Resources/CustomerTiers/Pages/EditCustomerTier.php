<?php

namespace App\Filament\Resources\CustomerTiers\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\CustomerTiers\CustomerTierResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomerTier extends EditRecord
{
    use ReportsDomainRules;

    protected static string $resource = CustomerTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
