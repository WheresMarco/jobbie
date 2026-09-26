<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\BasicAuth;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // There are no user accounts. Horizon sits behind the same Basic Auth
        // as the rest of the app, and stays closed when that is not set up.
        Gate::define('viewHorizon', static fn (?Authenticatable $user = null): bool => app()->environment('local') || BasicAuth::configured());
    }
}
