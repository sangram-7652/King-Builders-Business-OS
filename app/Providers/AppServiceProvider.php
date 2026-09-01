<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Branding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Branding::class, static fn (): Branding => Branding::fromConfig());
    }

    public function boot(): void
    {
        // --- Data-integrity guard rails -----------------------------------
        // Fail loudly in dev on lazy loads / bad mass-assignment / missing
        // attributes; stay lenient in production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Respect the proxied scheme when running behind nginx / a load balancer.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // --- Branding available to every view as `$branding` --------------
        View::share('branding', $this->app->make(Branding::class));
    }
}
