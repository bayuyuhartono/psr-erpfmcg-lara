<?php

namespace App\Filament\Resources\CustomerTiers\Pages;

use App\Filament\Resources\CustomerTiers\CustomerTierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomerTiers extends ListRecords
{
    protected static string $resource = CustomerTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
