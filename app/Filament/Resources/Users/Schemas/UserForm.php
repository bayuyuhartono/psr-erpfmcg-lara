<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Domain\Masters\Services\Licence;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Account'))->columns(2)->schema([
                TextInput::make('full_name')->label(__('Full name'))->required()->maxLength(255),
                TextInput::make('email')
                    ->label(__('Email'))
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                TextInput::make('password')
                    ->label(__('Password'))
                    ->password()
                    ->revealable()
                    ->required(fn (?User $record): bool => $record === null)
                    ->minLength(8)
                    // Left blank on an edit means "leave it as it is". Without
                    // this the field's empty value would be hashed and saved,
                    // locking the account out on every unrelated edit.
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(fn (?User $record): ?string => $record === null
                        ? null
                        : __('Leave blank to keep the current password.')),
                Select::make('branch_id')
                    ->label(__('Branch'))
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->preload(),
            ]),

            Section::make(__('Access'))
                ->description(__('A user with no role can sign in to nothing, so every account needs at least one.'))
                ->columns(2)
                ->schema([
                    Select::make('roles')
                        ->label(__('Roles'))
                        ->relationship('roles', 'name')
                        ->multiple()
                        ->preload()
                        ->required()
                        ->helperText(__('Approval limits come from the role, not the person.')),
                    Toggle::make('active')
                        ->label(__('Active'))
                        ->default(true)
                        // Locking yourself out of the only screen that could
                        // let you back in is not a mistake worth allowing.
                        ->disabled(fn (?User $record): bool => $record !== null && $record->is(Auth::user()))
                        ->helperText(fn (?User $record): string => $record !== null && $record->is(Auth::user())
                            ? __('You cannot deactivate your own account.')
                            : self::seatNote()),
                ]),
        ]);
    }

    /** Says what is left before the licence refuses, rather than after. */
    private static function seatNote(): string
    {
        $licence = app(Licence::class);
        $seats = $licence->seats();

        if ($seats === null) {
            return __('This installation has no seat limit.');
        }

        return __(':used of :seats seat(s) in use.', ['used' => $licence->seatsUsed(), 'seats' => $seats]);
    }
}
