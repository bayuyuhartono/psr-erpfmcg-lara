<?php

namespace App\Filament\Resources\PrintTemplates\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PrintTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_type')->label('Document')->badge()->searchable(),
                TextColumn::make('format')->badge()->color(fn (string $state): string => $state === 'dotmatrix' ? 'warning' : 'gray'),
                TextColumn::make('name')->searchable(),
                TextColumn::make('width_chars')->label('Width')->alignEnd()->placeholder('—'),
                TextColumn::make('copies')->alignEnd(),
                IconColumn::make('active')->boolean(),
            ])
            ->defaultSort('document_type')
            ->filters([
                SelectFilter::make('format')->options(['dotmatrix' => 'Dot matrix', 'a4' => 'A4']),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
