<?php

namespace App\Filament\Resources\JournalMappings\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\JournalMappings\JournalMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditJournalMapping extends EditRecord
{
    use ReportsDomainRules;

    protected static string $resource = JournalMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
