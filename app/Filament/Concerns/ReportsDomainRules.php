<?php

namespace App\Filament\Concerns;

use DomainException;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns a broken domain rule into something a warehouse supervisor can read.
 *
 * The rules live on models and in actions, so they throw wherever they are
 * violated — from the importer, the API, or here. Without this a base unit
 * locked by movement history, a partner losing a role it still uses, or goods
 * arriving too short-dated would all reach the user as a 500 page.
 *
 * Deliberately catches `DomainException` and nothing wider: a query error or a
 * null reference is a bug, and burying it in a red toast would hide it.
 */
trait ReportsDomainRules
{
    protected function handleRecordCreation(array $data): Model
    {
        return $this->reportingDomainRules(fn (): Model => parent::handleRecordCreation($data));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->reportingDomainRules(fn (): Model => parent::handleRecordUpdate($record, $data));
    }

    /** @param  callable(): Model  $save */
    private function reportingDomainRules(callable $save): Model
    {
        try {
            return $save();
        } catch (DomainException $exception) {
            Notification::make()
                ->title('That is not allowed')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            // Halt keeps the user on the form with what they typed intact.
            throw new Halt;
        }
    }
}
