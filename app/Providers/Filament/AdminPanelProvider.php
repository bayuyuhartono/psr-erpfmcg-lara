<?php

namespace App\Providers\Filament;

use App\Domain\Masters\Services\CompanyIdentity;
use App\Filament\Auth\Login;
use App\Filament\Widgets\BusinessOverview;
use App\Filament\Widgets\ExpiringStock;
use App\Filament\Widgets\SalesTrend;
use App\Http\Middleware\SetLocale;
use Brick\Math\BigDecimal;
use Filament\Enums\ThemeMode;
use Filament\Forms\Components\TextInput;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
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
            ->login(Login::class)
            // Both read at render time, not at boot, so a change on the
            // Company profile screen shows on the next page rather than after a
            // deploy. Filament's logo component picks one or the other: an
            // uploaded logo replaces the wordmark on the header and the login
            // screen, and the name comes back if the logo is removed.
            ->brandName(fn (): string => app(CompanyIdentity::class)->name())
            ->brandLogo(fn (): ?string => app(CompanyIdentity::class)->logoUrl())
            ->brandLogoHeight('2rem')
            ->favicon(fn (): ?string => app(CompanyIdentity::class)->faviconUrl())
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): View => view('filament.user-menu-language'),
            )
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
            // Without this the sidebar falls back to discovery order, which is
            // alphabetical by accident and put System in the middle. Ordered by
            // how often a distributor's staff actually open them: order entry
            // all day, the chart of accounts at month end, backups rarely.
            // The keys stay English because that is what the resources declare;
            // only the label is translated. Renaming a key here would quietly
            // unorder the group rather than error.
            ->navigationGroups(collect([
                'Sales', 'Partners', 'Catalogue', 'Pricing', 'Warehouse', 'Accounting', 'System',
            ])->mapWithKeys(fn (string $group): array => [
                $group => NavigationGroup::make($group)->label(fn (): string => __($group)),
            ])->all())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // The stock Filament widgets say who is logged in and which
            // version of Filament this is. Neither is what a distributor opens
            // the system for.
            ->widgets([BusinessOverview::class, SalesTrend::class, ExpiringStock::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // After StartSession: the chosen language lives in the session,
                // so applying it any earlier reads an empty one and silently
                // falls back to the default on every request.
                SetLocale::class,
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
