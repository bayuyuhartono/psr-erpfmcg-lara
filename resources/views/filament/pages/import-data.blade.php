<x-filament-panels::page>
    <form wire:submit="check">
        {{ $this->form }}

        <div class="mt-6 flex gap-3">
            <x-filament::button type="submit" outlined wire:loading.attr="disabled">
                Check the file
            </x-filament::button>

            <x-filament::button wire:click="commit" wire:loading.attr="disabled" :disabled="! $this->isImportable()">
                Import
            </x-filament::button>
        </div>
    </form>

    @if ($this->checked)
        @if ($this->problems)
            <x-filament::section class="mt-6" heading="Rows that need fixing">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Nothing has been saved. Correct these in the spreadsheet and check it again.
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
                    {{ $this->rowCount }} row(s) were read and every one of them would save.
                    Showing the first {{ count($this->sample) }}.
                </p>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <tbody>
                            @foreach ($this->sample as $row)
                                <tr class="border-t border-gray-200 dark:border-white/10">
                                    <td class="py-1 pr-4 text-gray-400">{{ $row['line'] }}</td>
                                    @foreach ($row['values'] as $value)
                                        <td class="py-1 pr-4 whitespace-nowrap">{{ $value ?: '—' }}</td>
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
