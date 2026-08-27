<?php

namespace App\Filament\Resources\AccountingPeriods\Tables;

use Carbon\CarbonImmutable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountingPeriodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')->sortable(),
                TextColumn::make('month')
                    ->formatStateUsing(fn (int $state): string => CarbonImmutable::create(null, $state)->format('F'))
                    ->sortable(),
                TextColumn::make('status')->badge()->color(
                    fn (string $state): string => $state === 'closed' ? 'danger' : 'success',
                ),
                TextColumn::make('closedBy.full_name')->label(__('Closed by'))->placeholder(__('—')),
                TextColumn::make('closed_at')->dateTime()->placeholder(__('—')),
            ])
            // Most recent first: the month someone is asking about is the one
            // that just ended.
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('year')->orderByDesc('month'))
            ->filters([
                SelectFilter::make('status')->options(['open' => 'Open', 'closed' => 'Closed']),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
