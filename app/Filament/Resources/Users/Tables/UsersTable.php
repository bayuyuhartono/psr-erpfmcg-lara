<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')->label(__('Full name'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('Email'))->searchable()->fontFamily('mono')->size('xs'),
                TextColumn::make('roles.name')->label(__('Roles'))->badge(),
                TextColumn::make('branch.name')->label(__('Branch'))->placeholder('—'),
                IconColumn::make('active')->label(__('Active'))->boolean(),
                TextColumn::make('last_login_at')
                    ->label(__('Last signed in'))
                    ->dateTime('d/m/Y H:i')
                    // An account nobody has ever used is the one to deactivate
                    // when the seats run out.
                    ->placeholder(__('never'))
                    ->sortable(),
            ])
            ->defaultSort('full_name')
            ->filters([
                TernaryFilter::make('active')->label(__('Active')),
                SelectFilter::make('roles')->label(__('Roles'))->relationship('roles', 'name')->multiple(),
            ])
            ->recordActions([
                EditAction::make(),
                // Soft delete, and never your own account: `created_by` on
                // every document points at these rows, and the record has to
                // stay readable for an audit long after the person leaves.
                DeleteAction::make()->hidden(fn (User $record): bool => $record->is(Auth::user())),
            ]);
    }
}
