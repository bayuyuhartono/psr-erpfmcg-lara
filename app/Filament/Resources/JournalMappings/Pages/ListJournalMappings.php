<?php

namespace App\Filament\Resources\JournalMappings\Pages;

use App\Filament\Resources\JournalMappings\JournalMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJournalMappings extends ListRecords
{
    protected static string $resource = JournalMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
