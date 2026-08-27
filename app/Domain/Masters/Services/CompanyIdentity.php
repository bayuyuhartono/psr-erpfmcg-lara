<?php

namespace App\Domain\Masters\Services;

use App\Domain\Masters\Models\CompanySetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * What the client is called, and what their logo is.
 *
 * Read on every page render, including the login screen, so the row is fetched
 * once per request and held. Registered as a singleton for that reason; `forget()`
 * is what the settings screen calls after a save.
 *
 * Every accessor survives there being no database at all. This is read while
 * rendering an error page, and during `migrate` on a fresh install the table
 * does not exist yet — a branding lookup must never be the reason an
 * installation cannot show a screen.
 */
final class CompanyIdentity
{
    public const DISK = 'public';

    public const DIRECTORY = 'branding';

    private ?CompanySetting $settings = null;

    private bool $loaded = false;

    public function name(): string
    {
        $name = $this->settings()?->legal_name;

        return filled($name) ? $name : config('app.name');
    }

    /**
     * The logo's public URL, or null when there is none.
     *
     * Null when the file is missing as well as when none was chosen: a broken
     * image in the header reads as a broken installation, where the company
     * name reads as one that has not been branded yet.
     */
    public function logoUrl(): ?string
    {
        return $this->publicUrl($this->settings()?->logo_url);
    }

    private function publicUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        try {
            $disk = Storage::disk(self::DISK);

            return $disk->exists($path) ? $disk->url($path) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The browser tab icon, falling back to the logo.
     *
     * A wordmark at sixteen pixels is a smear, so the fallback is a compromise
     * rather than the intent — but an approximate mark reads as this client's
     * system, where Laravel's default reads as somebody else's.
     */
    public function faviconUrl(): ?string
    {
        return $this->publicUrl($this->settings()?->favicon_url) ?? $this->logoUrl();
    }

    public function hasLogo(): bool
    {
        return $this->logoUrl() !== null;
    }

    public function settings(): ?CompanySetting
    {
        if ($this->loaded) {
            return $this->settings;
        }

        $this->loaded = true;

        try {
            // A fresh install renders the login screen before `migrate` runs.
            if (! Schema::hasTable('company_settings')) {
                return $this->settings = null;
            }

            $this->settings = CompanySetting::query()->first();
        } catch (Throwable) {
            $this->settings = null;
        }

        return $this->settings;
    }

    public function forget(): void
    {
        $this->loaded = false;
        $this->settings = null;
    }
}
