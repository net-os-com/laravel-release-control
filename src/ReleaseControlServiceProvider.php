<?php

namespace NetOs\ReleaseControl;

use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Support\ServiceProvider;
use NetOs\ReleaseControl\Services\ControlDocumentValidator;
use NetOs\ReleaseControl\Services\ControlStore;
use NetOs\ReleaseControl\Services\GcsControlStore;
use NetOs\ReleaseControl\Services\SharedControlReader;
use NetOs\ReleaseControl\Services\SharedMaintenanceMode;

class ReleaseControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/release-control.php', 'release-control');

        $this->app->singleton(ControlStore::class, fn () => new GcsControlStore(
            app(ControlDocumentValidator::class),
            config('release-control.bucket'),
            config('release-control.object'),
            config('release-control.environment'),
        ));
        // This singleton contains only environment-level control state.
        $this->app->singleton(SharedControlReader::class);
        $this->app->singleton(SharedMaintenanceMode::class);
        $this->callAfterResolving(MaintenanceModeManager::class, function (MaintenanceModeManager $manager): void {
            $manager->extend('release-control', fn () => app(SharedMaintenanceMode::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/release-control.php' => config_path('release-control.php')], 'release-control-config');

        // Warm only the reader object, never perform a network read at boot. Octane sandboxes then share the
        // environment-only singleton instead of rebuilding its lease for every request.
        config(['octane.warm' => array_values(array_unique([
            ...config('octane.warm', []), SharedControlReader::class,
        ]))]);
    }
}
