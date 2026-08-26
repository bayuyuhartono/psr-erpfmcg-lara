<?php

namespace App\Filament\Resources\ChartOfAccounts\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\ChartOfAccounts\ChartOfAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChartOfAccount extends CreateRecord
{
    use ReportsDomainRules;

    protected static string $resource = ChartOfAccountResource::class;
}
