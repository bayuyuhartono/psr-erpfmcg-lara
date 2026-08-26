<?php

namespace App\Providers\Filament;

use Brick\Math\BigDecimal;
use Filament\Enums\ThemeMode;
use Filament\Forms\Components\TextInput;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('FMCG Distributor ERP')
            ->colors(['primary' => Color::Indigo])
            // Filament ships a precompiled stylesheet containing only the
            // classes Filament itself uses. The custom pages here — order
            // entry, imports, backups — use Tailwind utilities that are not in
            // it, so the panel needs a theme Tailwind can build against this
            // application's own views.
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Light unless someone chooses otherwise. Following the operating
            // system is the wrong default for a system used on shared office
            // machines that nobody configures: staff read a dark screen as
            // broken. The switcher stays, so the preference is still theirs.
            ->defaultThemeMode(ThemeMode::Light)
            ->themeSwitcher()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([AccountWidget::class, FilamentInfoWidget::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }

    /**
     * Money and quantities are `BigDecimal` all the way down — that is what
     * keeps them out of PHP float arithmetic. Filament's inputs expect a
     * scalar, so without this every price, quantity and percentage field in
     * every module dies rendering an edit form.
     *
     * This covers plain text inputs. Numeric ones need `DecimalInput`, because
     * Filament's `->numeric()` casts state through `floatval()` before any
     * formatting runs.
     */
    public function boot(): void
    {
        TextInput::configureUsing(fn (TextInput $input) => $input->formatStateUsing(
            fn (mixed $state): mixed => $state instanceof BigDecimal ? (string) $state : $state,
        ));
    }
}
