<?php

namespace App\Filament\Resources\AccountingPeriods\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\AccountingPeriods\AccountingPeriodResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccountingPeriod extends CreateRecord
{
    use ReportsDomainRules;

    protected static string $resource = AccountingPeriodResource::class;
}
