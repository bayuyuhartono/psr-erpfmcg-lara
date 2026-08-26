<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use ReportsDomainRules;

    protected static string $resource = ProductResource::class;
}
