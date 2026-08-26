<?php

namespace App\Providers;

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
        //
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
