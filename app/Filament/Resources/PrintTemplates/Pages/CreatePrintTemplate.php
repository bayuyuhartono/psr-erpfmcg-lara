<?php

namespace App\Filament\Resources\PrintTemplates\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\PrintTemplates\PrintTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePrintTemplate extends CreateRecord
{
    use ReportsDomainRules;

    protected static string $resource = PrintTemplateResource::class;
}
