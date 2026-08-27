<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\ReportsDomainRules;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    // `SeatsExhausted` is a DomainException, so running out of seats arrives
    // as a readable notice rather than a 500 page.
    use ReportsDomainRules;

    protected static string $resource = UserResource::class;
}
