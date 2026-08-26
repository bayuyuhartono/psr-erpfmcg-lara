<?php

namespace App\Filament\Resources\BusinessPartners\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\BusinessPartners\BusinessPartnerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBusinessPartner extends CreateRecord
{
    use ReportsDomainRules;

    protected static string $resource = BusinessPartnerResource::class;
}
