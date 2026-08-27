@php
    use App\Support\Localisation\Locales;

    $current = Locales::current();
@endphp

{{-- The same control in the user menu and on the login screen, so the two
     cannot drift apart. Styled as a segmented pair rather than a dropdown:
     with exactly two languages a menu costs a click for nothing. --}}
<div class="flex items-center gap-1 {{ $class ?? '' }}">
    @foreach (Locales::SUPPORTED as $locale => $label)
        <a
            href="{{ route('locale.switch', $locale) }}"
            @class([
                'rounded-md px-2 py-1 text-xs font-medium transition',
                'bg-primary-600 text-white' => $locale === $current,
                'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5' => $locale !== $current,
            ])
            @if ($locale === $current) aria-current="true" @endif
            title="{{ $label }}"
        >{{ Locales::shortLabel($locale) }}</a>
    @endforeach
</div>
