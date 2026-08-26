<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-3">
        <div class="relative md:col-span-2">
            <label class="text-sm font-medium">Customer</label>
            <input
                wire:model.live.debounce.150ms="customerSearch"
                wire:keydown.enter.prevent="chooseCustomer"
                type="text"
                autocomplete="off"
                placeholder="Code or name…"
                class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-white/10"
            />

            @if ($this->customerMatches)
                <ul class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-white/10 dark:bg-gray-900">
                    @foreach ($this->customerMatches as $match)
                        <li>
                            <button
                                type="button"
                                wire:click="chooseCustomer('{{ $match['id'] }}')"
                                class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5"
                            >{{ $match['label'] }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        <div class="flex items-end">
            @if ($this->creditNote)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $this->creditNote }}</p>
            @endif
        </div>
    </div>

    <div
        class="mt-6"
        x-data
        x-on:focus-search.window="$nextTick(() => $refs.search?.focus())"
    >
        <div class="grid gap-3 md:grid-cols-6">
            <div class="md:col-span-4 relative">
                <label class="text-sm font-medium">Product</label>
                <input
                    x-ref="search"
                    wire:model.live.debounce.150ms="search"
                    wire:keydown.enter.prevent="addLine"
                    wire:keydown.arrow-down.prevent="moveHighlight(1)"
                    wire:keydown.arrow-up.prevent="moveHighlight(-1)"
                    wire:keydown.escape="$set('matches', [])"
                    type="text"
                    autocomplete="off"
                    placeholder="Code, name or barcode…  ↑↓ to choose, Enter to add"
                    class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-white/10"
                />

                @if ($this->matches)
                    <ul class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-white/10 dark:bg-gray-900">
                        @foreach ($this->matches as $index => $match)
                            <li>
                                <button
                                    type="button"
                                    wire:click="choose('{{ $match['id'] }}')"
                                    class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5 {{ $this->highlight === $index ? 'bg-primary-50 dark:bg-primary-500/10' : '' }}"
                                >
                                    {{ $match['label'] }}
                                    <span class="text-gray-400">/ {{ $match['uom'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div>
                <label class="text-sm font-medium">Qty</label>
                <input
                    wire:model="quantity"
                    wire:keydown.enter.prevent="addLine"
                    type="text"
                    inputmode="decimal"
                    class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-white/10"
                />
            </div>

            <div class="flex items-end">
                <x-filament::button wire:click="addLine" outlined class="w-full">Add</x-filament::button>
            </div>
        </div>
    </div>

    <x-filament::section class="mt-6" heading="Lines">
        @if ($this->lines)
            <table class="w-full text-sm">
                <thead class="text-gray-500 dark:text-gray-400">
                    <tr class="text-left">
                        <th class="pb-2">SKU</th>
                        <th class="pb-2">Product</th>
                        <th class="pb-2">Unit</th>
                        <th class="pb-2 text-right">Qty</th>
                        <th class="pb-2 text-right">Price</th>
                        <th class="pb-2 text-right">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->lines as $index => $line)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-1.5">{{ $line['sku'] }}</td>
                            <td class="py-1.5">{{ $line['name'] }}</td>
                            <td class="py-1.5">{{ $line['uom'] }}</td>
                            <td class="py-1.5 text-right">{{ $line['qty'] }}</td>
                            <td class="py-1.5 text-right">{{ number_format((float) $line['unit_price'], 2) }}</td>
                            <td class="py-1.5 text-right">{{ number_format((float) $line['line_total'], 2) }}</td>
                            <td class="py-1.5 text-right">
                                <button type="button" wire:click="removeLine({{ $index }})" class="text-danger-600 hover:underline">remove</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-200 font-semibold dark:border-white/10">
                        <td class="pt-2" colspan="5">{{ count($this->lines) }} line(s)</td>
                        <td class="pt-2 text-right">{{ number_format((float) $this->total(), 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing yet. Find a product above and press Enter.</p>
        @endif

        <div class="mt-6 flex gap-3">
            <x-filament::button wire:click="save" :disabled="! $this->lines">Save order</x-filament::button>
            <x-filament::button wire:click="copyPrevious" color="gray" outlined>Copy last order</x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>
