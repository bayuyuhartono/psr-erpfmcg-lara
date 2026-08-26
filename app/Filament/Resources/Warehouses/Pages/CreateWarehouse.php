<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\Warehouses\WarehouseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWarehouse extends CreateRecord
{
    use ReportsDomainRules;

    protected static string $resource = WarehouseResource::class;
}
