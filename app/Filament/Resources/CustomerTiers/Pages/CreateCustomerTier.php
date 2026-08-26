<?php

namespace App\Filament\Resources\CustomerTiers\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\CustomerTiers\CustomerTierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomerTier extends CreateRecord
{
    use ReportsDomainRules;

    protected static string $resource = CustomerTierResource::class;
}
