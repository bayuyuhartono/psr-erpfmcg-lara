<x-filament-panels::page>
    @if ($this->verified)
        <x-filament::section heading="Last restore check">
            <p class="text-sm text-success-600 dark:text-success-400">{{ $this->verified }}</p>
        </x-filament::section>
    @endif

    <x-filament::section @class(['mt-6' => $this->verified]) heading="Backups on this server">
        @php($backups = $this->backups())

        @if ($backups)
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-gray-500 dark:text-gray-400">
                        <tr class="text-left">
                            <th class="pb-2 font-medium">{{ __('File') }}</th>
                            <th class="pb-2 font-medium">{{ __('Taken') }}</th>
                            <th class="pb-2 text-right font-medium">{{ __('Size') }}</th>
                            <th class="pb-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($backups as $backup)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-mono text-xs">{{ $backup['name'] }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $backup['taken_at'] }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $backup['size'] }}</td>
                                <td class="py-2 pl-4 text-right">
                                    <div class="flex justify-end gap-4">
                                        <x-filament::link
                                            tag="button"
                                            wire:click="download('{{ $backup['name'] }}')"
                                            size="sm"
                                        >{{ __('Download') }}</x-filament::link>

                                        <x-filament::link
                                            tag="button"
                                            wire:click="verify('{{ $backup['name'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="verify('{{ $backup['name'] }}')"
                                            size="sm"
                                        >{{ __('Check it restores') }}</x-filament::link>

                                        <x-filament::link
                                            tag="button"
                                            color="danger"
                                            size="sm"
                                            wire:click="delete('{{ $backup['name'] }}')"
                                            wire:confirm="Delete {{ $backup['name'] }}? This cannot be undone."
                                        >{{ __('Delete') }}</x-filament::link>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('No backups yet. Take one before the next upgrade.') }}
            </p>
        @endif

        <p class="mt-6 text-xs text-gray-500 dark:text-gray-400">
            Written to <code class="font-mono">{{ config('backup.path') }}</code> on this server.
            Copying them somewhere else is still a manual step — automatic offsite copies
            need to know where offsite is.
        </p>
    </x-filament::section>
</x-filament-panels::page>
