<x-filament-panels::page>
    @php($setup = $this->setupNeeded())

    @if ($setup)
        <x-filament::section heading="Before you can take an order">
            <ul class="space-y-1 text-sm text-warning-600 dark:text-warning-400">
                @foreach ($setup as $missing)
                    <li>{{ $missing }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    <div
        x-data
        x-on:focus-search.window="$nextTick(() => $refs.search?.focus())"
        x-on:focus-quantity.window="$nextTick(() => $refs.quantity?.select())"
    >
        {{-- Customer, and where this order leaves their credit --}}
        <x-filament::section>
            <div class="grid gap-4 md:grid-cols-3">
                <div class="relative md:col-span-2">
                    <label class="mb-1 block text-sm font-medium">{{ __('Customer') }}</label>

                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="text"
                            wire:model.live.debounce.150ms="customerSearch"
                            wire:keydown.enter.prevent="chooseCustomer"
                            autocomplete="off"
                            placeholder="Code or name…"
                        />
                    </x-filament::input.wrapper>

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

                @php($credit = $this->creditPosition())

                @if ($credit)
                    <div @class([
                        'rounded-lg p-3 text-sm',
                        'bg-gray-50 dark:bg-white/5' => ! $credit['over'],
                        'bg-danger-50 dark:bg-danger-500/10' => $credit['over'],
                    ])>
                        <dl class="space-y-0.5">
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Credit limit') }}</dt>
                                <dd>{{ number_format((float) $credit['limit'], 0, ',', '.') }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Already owes') }}</dt>
                                <dd>{{ number_format((float) $credit['owed'], 0, ',', '.') }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('This order') }}</dt>
                                <dd>{{ number_format((float) $credit['order'], 0, ',', '.') }}</dd>
                            </div>
                            <div class="mt-1 flex justify-between gap-4 border-t border-gray-200 pt-1 font-medium dark:border-white/10">
                                <dt>{{ __('Left after this') }}</dt>
                                <dd @class(['text-danger-600 dark:text-danger-400' => $credit['over']])>
                                    {{ number_format((float) $credit['remaining'], 0, ',', '.') }}
                                </dd>
                            </div>
                        </dl>

                        @if ($credit['over'])
                            {{-- Said before the order is saved, not after. --}}
                            <p class="mt-2 text-xs text-danger-600 dark:text-danger-400">
                                {{ $credit['reason'] }} — this will be held for release.
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- The line being typed --}}
        <x-filament::section class="mt-6">
            <div class="grid gap-3 md:grid-cols-12">
                <div class="relative md:col-span-6">
                    <label class="mb-1 block text-sm font-medium">{{ __('Product') }}</label>

                    <x-filament::input.wrapper>
                        <x-filament::input
                            x-ref="search"
                            type="text"
                            wire:model.live.debounce.150ms="search"
                            wire:focus="openList"
                            wire:keydown.enter.prevent="addLine"
                            wire:keydown.arrow-down.prevent="moveHighlight(1)"
                            wire:keydown.arrow-up.prevent="moveHighlight(-1)"
                            wire:keydown.escape="closeList"
                            autocomplete="off"
                            placeholder="Search a product, or press ↓ to browse"
                        />
                    </x-filament::input.wrapper>

                    @if ($this->matches)
                        <ul class="absolute z-10 mt-1 max-h-80 w-full overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-white/10 dark:bg-gray-900">
                            @foreach ($this->matches as $index => $match)
                                <li>
                                    <button
                                        type="button"
                                        wire:click="choose('{{ $match['id'] }}')"
                                        @class([
                                            'flex w-full items-baseline justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5',
                                            'bg-primary-50 dark:bg-primary-500/10' => $this->highlight === $index,
                                        ])
                                    >
                                        <span class="min-w-0">
                                            <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $match['sku'] }}</span>
                                            <span class="ml-2">{{ $match['name'] }}</span>
                                        </span>
                                        <span class="shrink-0 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                            @if ($match['price'] !== '')
                                                {{ $match['price'] }} / {{ $match['uom'] }}
                                            @else
                                                <span class="text-warning-600 dark:text-warning-400">no price</span>
                                            @endif
                                            @if ($match['available'] !== '')
                                                · {{ $match['available'] }} on hand
                                            @endif
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @elseif ($this->search !== '')
                        <p class="absolute z-10 mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-500 shadow-lg dark:border-white/10 dark:bg-gray-900 dark:text-gray-400">
                            Nothing matches “{{ $this->search }}”.
                        </p>
                    @endif
                </div>

                {{-- Five cartons and five pieces are not the same order. --}}
                <div class="md:col-span-3">
                    <label class="mb-1 block text-sm font-medium">{{ __('Unit') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="selectedUom" :disabled="! $this->unitOptions()">
                            @forelse ($this->unitOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @empty
                                <option value="">—</option>
                            @endforelse
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Qty</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            x-ref="quantity"
                            type="text"
                            inputmode="decimal"
                            wire:model="quantity"
                            wire:keydown.enter.prevent="addLine"
                        />
                    </x-filament::input.wrapper>
                </div>

                <div class="flex items-end md:col-span-1">
                    <x-filament::button wire:click="addLine" outlined class="w-full">Add</x-filament::button>
                </div>
            </div>

            <p class="mt-2 text-xs text-gray-400">↑↓ to move through the list, Enter to add the highlighted product.</p>
        </x-filament::section>

        {{-- What is on the order so far --}}
        <x-filament::section class="mt-6" heading="Lines">
            @if ($this->lines)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr class="text-left">
                                <th class="pb-2 font-medium">{{ __('SKU') }}</th>
                                <th class="pb-2 font-medium">{{ __('Product') }}</th>
                                <th class="pb-2 font-medium">{{ __('Unit') }}</th>
                                <th class="pb-2 text-right font-medium">Qty</th>
                                <th class="pb-2 text-right font-medium">{{ __('Price') }}</th>
                                <th class="pb-2 text-right font-medium">{{ __('Total') }}</th>
                                <th class="w-8"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->lines as $index => $line)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="py-1.5 font-mono text-xs">{{ $line['sku'] }}</td>
                                    <td class="py-1.5">{{ $line['name'] }}</td>
                                    <td class="py-1.5">{{ $line['uom'] }}</td>
                                    <td class="py-1.5 text-right">
                                        {{-- Editable, and re-priced: volume bands turn on quantity. --}}
                                        <input
                                            type="text"
                                            inputmode="decimal"
                                            wire:model.live.debounce.400ms="lines.{{ $index }}.qty"
                                            class="w-20 rounded-md border-gray-300 bg-transparent px-2 py-1 text-right text-sm dark:border-white/10"
                                        />
                                    </td>
                                    <td class="py-1.5 text-right tabular-nums">{{ number_format((float) $line['unit_price'], 0, ',', '.') }}</td>
                                    <td class="py-1.5 text-right tabular-nums">{{ number_format((float) $line['line_total'], 0, ',', '.') }}</td>
                                    <td class="py-1.5 text-right">
                                        <button
                                            type="button"
                                            wire:click="removeLine({{ $index }})"
                                            class="text-gray-400 hover:text-danger-600"
                                            aria-label="Remove {{ $line['sku'] }}"
                                        >
                                            <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 font-semibold dark:border-white/10">
                                <td class="pt-2" colspan="5">{{ count($this->lines) }} line(s)</td>
                                <td class="pt-2 text-right tabular-nums">{{ number_format((float) $this->total(), 0, ',', '.') }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Nothing yet. Find a product above and press Enter.') }}
                </p>
            @endif

            <x-slot name="footerActions">
                <x-filament::button wire:click="save" :disabled="! $this->lines">
                    {{ __('Save order') }}
                    @if ($this->lines)
                        · {{ number_format((float) $this->total(), 0, ',', '.') }}
                    @endif
                </x-filament::button>

                <x-filament::button wire:click="copyPrevious" color="gray" outlined>
                    {{ __('Copy last order') }}
                </x-filament::button>
            </x-slot>
        </x-filament::section>
    </div>
</x-filament-panels::page>
