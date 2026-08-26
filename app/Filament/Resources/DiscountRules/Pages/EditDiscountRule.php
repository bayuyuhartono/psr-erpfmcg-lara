<?php

namespace App\Filament\Resources\DiscountRules\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\DiscountRules\DiscountRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditDiscountRule extends EditRecord
{
    use ReportsDomainRules;

    protected static string $resource = DiscountRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
