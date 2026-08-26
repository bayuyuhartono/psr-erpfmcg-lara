<?php

namespace App\Filament\Resources\DiscountRules\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\DiscountRules\DiscountRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDiscountRule extends CreateRecord
{
    use ReportsDomainRules;

    protected static string $resource = DiscountRuleResource::class;
}
