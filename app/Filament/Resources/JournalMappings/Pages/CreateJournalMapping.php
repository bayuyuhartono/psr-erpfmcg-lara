<?php

namespace App\Filament\Resources\JournalMappings\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\JournalMappings\JournalMappingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateJournalMapping extends CreateRecord
{
    use ReportsDomainRules;

    protected static string $resource = JournalMappingResource::class;
}
