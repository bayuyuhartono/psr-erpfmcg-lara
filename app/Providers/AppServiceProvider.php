<?php

namespace App\Providers;

use App\Domain\Masters\Services\CompanyIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Read on every render, including the login screen. A singleton so the
        // row is fetched once a request rather than once a component.
        $this->app->singleton(CompanyIdentity::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // What a product cost us, and therefore what we make on it, is the one
        // thing an owner asks to be kept from the sales floor (F-70). Defined
        // once as an ability so the screens, the exports and the Phase 2 API
        // all ask the same question.
        Gate::define('view-cost', fn (User $user): bool => $user->hasPermission('product.view_cost'));
    }
}
