<?php

namespace App\Filament\Resources\AccountingPeriods\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\AccountingPeriods\AccountingPeriodResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditAccountingPeriod extends EditRecord
{
    use ReportsDomainRules;

    protected static string $resource = AccountingPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
