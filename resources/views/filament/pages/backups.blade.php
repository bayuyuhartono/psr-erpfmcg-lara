<x-filament-panels::page>
    @if ($this->verified)
        <x-filament::section heading="Last restore check">
            <p class="text-sm text-success-600 dark:text-success-400">{{ $this->verified }}</p>
        </x-filament::section>
    @endif

    <x-filament::section heading="Backups on this server" class="mt-6">
        @php($backups = $this->backups())

        @if ($backups)
            <table class="w-full text-sm">
                <thead class="text-gray-500 dark:text-gray-400">
                    <tr class="text-left">
                        <th class="pb-2">File</th>
                        <th class="pb-2">Taken</th>
                        <th class="pb-2 text-right">Size</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($backups as $backup)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 font-mono text-xs">{{ $backup['name'] }}</td>
                            <td class="py-2">{{ $backup['taken_at'] }}</td>
                            <td class="py-2 text-right">{{ $backup['size'] }}</td>
                            <td class="py-2 text-right">
                                <div class="flex justify-end gap-3">
                                    <button type="button" wire:click="download('{{ $backup['name'] }}')" class="text-primary-600 hover:underline">download</button>
                                    <button type="button" wire:click="verify('{{ $backup['name'] }}')" wire:loading.attr="disabled" class="text-primary-600 hover:underline">check it restores</button>
                                    <button
                                        type="button"
                                        wire:click="delete('{{ $backup['name'] }}')"
                                        wire:confirm="Delete {{ $backup['name'] }}? This cannot be undone."
                                        class="text-danger-600 hover:underline"
                                    >delete</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No backups yet. Take one before the next upgrade.
            </p>
        @endif

        <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">
            Written to <code class="text-xs">{{ config('backup.path') }}</code> on this server.
            Copying them somewhere else is still a manual step — automatic offsite copies
            need to know where offsite is.
        </p>
    </x-filament::section>
</x-filament-panels::page>
