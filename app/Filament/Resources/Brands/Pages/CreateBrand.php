<?php

namespace App\Filament\Resources\Brands\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\Brands\BrandResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBrand extends CreateRecord
{
    use ReportsDomainRules;

    protected static string $resource = BrandResource::class;
}
