<?php

namespace App\Filament\Resources\PrintTemplates\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The screen that keeps print layout out of code. A client whose stationery
 * needs the tax number three lines higher edits the body here.
 */
class PrintTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                Select::make('document_type')->options([
                    'sales_invoice' => 'Invoice',
                    'delivery' => 'Delivery note',
                    'invoice_exchange' => 'Invoice exchange receipt',
                ])->required(),
                Select::make('format')->options([
                    'dotmatrix' => 'Dot matrix — continuous form',
                    'a4' => 'A4 — laser or PDF',
                ])->required()->live(),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('width_chars')->label('Width (characters)')->numeric()->default(80)
                    ->visible(fn (callable $get): bool => $get('format') === 'dotmatrix')
                    ->helperText('80 for standard continuous form, 136 for wide.'),
                TextInput::make('copies')->numeric()->default(2)
                    ->visible(fn (callable $get): bool => $get('format') === 'dotmatrix')
                    ->helperText('How many parts the paper has. Printed as one run separated by form feeds.'),
                Toggle::make('active')->default(true),
            ]),

            Section::make('Layout')
                ->description('{{ invoice.number }} inserts a value. Formatters chain: {{ invoice.total | money | rpad:14 }}. {{# lines }} … {{/ lines }} repeats per row. {{ copy }} becomes ORIGINAL, COPY, FILE COPY.')
                ->schema([
                    Textarea::make('body')
                        ->required()
                        ->rows(24)
                        ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; white-space: pre;'])
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
