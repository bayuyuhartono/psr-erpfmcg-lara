<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Masters\Services\Licence;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * The seat count, where someone will read it before adding a user rather
     * than after the licence refuses them.
     */
    public function getSubheading(): string|Htmlable|null
    {
        $licence = app(Licence::class);
        $seats = $licence->seats();

        if ($seats === null) {
            return __('This installation has no seat limit.');
        }

        return __(':used of :seats seat(s) in use.', ['used' => $licence->seatsUsed(), 'seats' => $seats]);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
