<?php

namespace App\Filament\Resources\BusinessPartners\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\BusinessPartners\BusinessPartnerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditBusinessPartner extends EditRecord
{
    use ReportsDomainRules;

    protected static string $resource = BusinessPartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
