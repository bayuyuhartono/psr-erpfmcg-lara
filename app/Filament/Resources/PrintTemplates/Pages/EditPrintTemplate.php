<?php

namespace App\Filament\Resources\PrintTemplates\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\PrintTemplates\PrintTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPrintTemplate extends EditRecord
{
    use ReportsDomainRules;

    protected static string $resource = PrintTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
