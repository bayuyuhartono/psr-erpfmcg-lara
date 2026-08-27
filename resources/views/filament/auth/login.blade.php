@php($identity = $this->identity())
@php($logo = $identity->logoUrl())

<div class="fi-simple-page">
    {{-- The mark again, for the single-column phone layout where the panel
         carrying it is not rendered at all. --}}
    <div class="mb-8 lg:hidden">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $identity->name() }}" class="h-9 w-auto" />
        @else
            <p class="text-lg font-semibold tracking-tight text-gray-950 dark:text-white">
                {{ $identity->name() }}
            </p>
        @endif
    </div>

    <h1 class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
        {{ $this->getHeading() }}
    </h1>
    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
        {{ __('Use the account your administrator set up for you.') }}
    </p>

    <div class="mt-8">
        {{ $this->content }}
    </div>
</div>
