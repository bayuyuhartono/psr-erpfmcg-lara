<?php

namespace App\Support\Localisation;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

/**
 * The two languages this system is sold in.
 *
 * Indonesian is the default because the people using it all day — order entry,
 * the warehouse, collections — work in it. English is kept because the client's
 * accountant and whoever installs the system often do not.
 *
 * The choice is held in the session rather than on the user, so it works on the
 * login screen, where there is no user yet.
 */
final class Locales
{
    public const SESSION_KEY = 'locale';

    public const SUPPORTED = [
        'id' => 'Bahasa Indonesia',
        'en' => 'English',
    ];

    public static function isSupported(string $locale): bool
    {
        return array_key_exists($locale, self::SUPPORTED);
    }

    public static function default(): string
    {
        $configured = (string) config('app.locale');

        return self::isSupported($configured) ? $configured : 'id';
    }

    public static function current(): string
    {
        return App::getLocale();
    }

    public static function label(string $locale): string
    {
        return self::SUPPORTED[$locale] ?? $locale;
    }

    /** Short form for a switcher that has no room for "Bahasa Indonesia". */
    public static function shortLabel(string $locale): string
    {
        return match ($locale) {
            'id' => 'ID',
            'en' => 'EN',
            default => strtoupper($locale),
        };
    }

    public static function remember(string $locale): void
    {
        if (! self::isSupported($locale)) {
            return;
        }

        Session::put(self::SESSION_KEY, $locale);
        App::setLocale($locale);
    }

    public static function fromSession(): string
    {
        $locale = Session::get(self::SESSION_KEY);

        return (is_string($locale) && self::isSupported($locale)) ? $locale : self::default();
    }
}
