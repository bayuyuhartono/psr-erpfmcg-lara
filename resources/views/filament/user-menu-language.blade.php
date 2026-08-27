{{-- Sits in the user menu next to the theme switcher, which is where a
     preference nobody changes twice belongs. --}}
<div class="flex items-center justify-between gap-3 px-3 py-2">
    <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Language') }}</span>
    @include('filament.language-switcher')
</div>
