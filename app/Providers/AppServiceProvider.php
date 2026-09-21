<?php

declare(strict_types=1);

namespace App\Providers;

use App\Communication\CommunicationManager;
use App\Enums\RoleName;
use App\Masters\MasterRegistry;
use App\Models\Booking;
use App\Models\User;
use App\Observers\BookingCommissionObserver;
use App\Policies\MasterDataPolicy;
use App\Support\Branding;
use App\Support\BrandingConfigWriter;
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
        $this->app->singleton(CommunicationManager::class);

        // Safety net, independent of any individual test remembering to
        // fake it: in the `testing` environment, BrandingConfigWriter (used
        // by GeneratePlotKycReceiptAction to persist the Plot KYC Receipt's
        // Director Name / PAN) NEVER defaults to the real .env. A test that
        // needs to assert on the written content still binds its own
        // throwaway path; this only stops an omission from ever reaching
        // the project's real .env.
        if ($this->app->environment('testing')) {
            $this->app->singleton(
                BrandingConfigWriter::class,
                static fn (): BrandingConfigWriter => new BrandingConfigWriter(storage_path('framework/testing/branding.env')),
            );
        }
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

        // M14.4: keep commission cases in step with the M6 booking lifecycle.
        Booking::observe(BookingCommissionObserver::class);

        // --- Branding available to every view as `$branding` --------------
        View::share('branding', $this->app->make(Branding::class));
    }
}
