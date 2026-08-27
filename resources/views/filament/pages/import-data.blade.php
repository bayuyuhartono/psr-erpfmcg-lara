<x-filament-panels::page>
    <form wire:submit="check">
        <x-filament::section>
            {{ $this->form }}

            <x-slot name="footerActions">
                <x-filament::button type="submit" outlined wire:loading.attr="disabled" wire:target="check">
                    {{ __('Check the file') }}
                </x-filament::button>

                <x-filament::button wire:click="commit" wire:loading.attr="disabled" wire:target="commit" :disabled="! $this->isImportable()">
                    Import
                    @if ($this->isImportable())
                        · {{ number_format($this->rowCount) }} row(s)
                    @endif
                </x-filament::button>
            </x-slot>
        </x-filament::section>
    </form>

    @if ($this->checked)
        @if ($this->problems)
            <x-filament::section class="mt-6" heading="Rows that need fixing">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Nothing has been saved. Correct these in the spreadsheet and check it again.') }}
                </p>

                <ul class="mt-4 space-y-1 text-sm">
                    @foreach ($this->problems as $problem)
                        <li class="text-danger-600 dark:text-danger-400">{{ $problem }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @else
            <x-filament::section class="mt-6" heading="Ready to import">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ number_format($this->rowCount) }} row(s) were read and every one of them would save.
                    Showing the first {{ count($this->sample) }}.
                </p>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr class="text-left">
                                <th class="pb-2 font-medium">Row</th>
                                @foreach (array_keys($this->sample[0]['values'] ?? []) as $column)
                                    <th class="pb-2 pr-4 font-medium">{{ Str::headline($column) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->sample as $row)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="py-1.5 pr-4 font-mono text-xs text-gray-400">{{ $row['line'] }}</td>
                                    @foreach ($row['values'] as $value)
                                        <td class="py-1.5 pr-4 whitespace-nowrap">{{ $value ?: '—' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
