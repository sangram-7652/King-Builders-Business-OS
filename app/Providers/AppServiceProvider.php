<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\RoleName;
use App\Masters\MasterRegistry;
use App\Models\Payment;
use App\Models\User;
use App\Observers\PaymentCollectionObserver;
use App\Policies\MasterDataPolicy;
use App\Support\Branding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
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

        // --- Authorization ----------------------------------------------
        // SUPER ADMIN bypasses every gate/permission check. This is the ONLY
        // place a role is checked directly — everything else uses permissions.
        Gate::before(static function (User $user, string $ability): ?bool {
            return $user->hasRole(RoleName::SuperAdmin->value) ? true : null;
        });

        // Every Master Data model shares one policy (M2).
        foreach (MasterRegistry::modelClasses() as $modelClass) {
            Gate::policy($modelClass, MasterDataPolicy::class);
        }

        // M8: keep collection state in step with M7 payment state.
        Payment::observe(PaymentCollectionObserver::class);

        // --- Branding available to every view as `$branding` --------------
        View::share('branding', $this->app->make(Branding::class));
    }
}
