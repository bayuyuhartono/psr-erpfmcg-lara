<?php

namespace App\Filament\Resources\PriceLists\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\PriceLists\PriceListResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePriceList extends CreateRecord
{
    use ReportsDomainRules;

    protected static string $resource = PriceListResource::class;
}
