@php
    use App\Domain\Masters\Services\CompanyIdentity;

    $livewire ??= null;
    $identity ??= app(CompanyIdentity::class);
    $logo = $identity->logoUrl();
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    {{--
        Two columns on a desktop, one on a phone. The left is decoration and is
        dropped entirely below `lg` rather than stacked above the form: on a
        phone in a warehouse the only thing wanted is the password field.
    --}}
    <div class="flex min-h-screen">
        <div class="relative hidden w-1/2 items-center justify-center overflow-hidden bg-gray-950 p-12 lg:flex xl:w-[55%]">
            {{-- Colour comes from the panel's own palette, so changing
                 `->colors()` changes this with it. --}}
            <div
                class="pointer-events-none absolute inset-0 opacity-90"
                style="background-image:
                    radial-gradient(60rem 60rem at 15% 10%, color-mix(in oklab, var(--primary-500) 45%, transparent), transparent 60%),
                    radial-gradient(45rem 45rem at 85% 85%, color-mix(in oklab, var(--primary-700) 55%, transparent), transparent 60%);"
            ></div>

            {{-- A faint grid, to stop the gradient reading as an empty screen. --}}
            <div
                class="pointer-events-none absolute inset-0 opacity-[0.07]"
                style="background-image:
                    linear-gradient(to right, white 1px, transparent 1px),
                    linear-gradient(to bottom, white 1px, transparent 1px);
                    background-size: 3rem 3rem;"
            ></div>

            <div class="relative">
                @if ($logo)
                    {{-- Inverted to white: a logo drawn for white paper
                         disappears against a dark panel. --}}
                    <img
                        src="{{ $logo }}"
                        alt="{{ $identity->name() }}"
                        class="max-h-32 w-auto max-w-md brightness-0 invert"
                    />
                @else
                    <p class="text-3xl font-semibold tracking-tight text-white">{{ $identity->name() }}</p>
                @endif
            </div>
        </div>

        <div class="relative flex w-full flex-col justify-center bg-white px-6 py-12 sm:px-12 lg:w-1/2 xl:w-[45%] dark:bg-gray-950">
            {{-- Before signing in, not after: someone who cannot read the form
                 cannot be asked to sign in first to change its language. --}}
            <div class="absolute right-6 top-6">
                @include('filament.language-switcher')
            </div>

            <main id="fi-main-content" tabindex="-1" class="mx-auto w-full max-w-sm">
                {{ $slot }}
            </main>
        </div>
    </div>
</x-filament-panels::layout.base>
