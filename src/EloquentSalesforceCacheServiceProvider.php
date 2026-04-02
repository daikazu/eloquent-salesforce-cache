<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache;

use Daikazu\EloquentSalesforceCache\Adapters\CachedSalesforceAdapter;
use Daikazu\EloquentSalesforceCache\Commands\CacheStatusCommand;
use Daikazu\EloquentSalesforceCache\Commands\InvalidateCacheCommand;
use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;
use Daikazu\EloquentSalesforceCache\Support\ModelDiscovery;
use Daikazu\EloquentSalesforceCache\Support\TagRegistry;
use Daikazu\EloquentSalesforceObjects\Support\AuthenticationManager;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Illuminate\Support\ServiceProvider;
use Override;

class EloquentSalesforceCacheServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/salesforce-cache.php', 'salesforce-cache');

        $this->app->singleton(TagRegistry::class);
        $this->app->singleton(CacheInvalidator::class);
        $this->app->singleton(ModelDiscovery::class);

        // Bind in a booting callback so it runs AFTER all providers have registered.
        // Direct binding here would be overwritten by EloquentSalesforceObjectsServiceProvider
        // because package discovery is alphabetical and "cache" sorts before "objects".
        $this->app->booting(function (): void {
            if (! config('salesforce-cache.enabled', true)) {
                return;
            }

            $this->app->singleton(SalesforceAdapter::class, fn ($app): CachedSalesforceAdapter => new CachedSalesforceAdapter(
                $app->make(AuthenticationManager::class),
            ));
        });
    }

    public function boot(): void
    {
        if (config('salesforce-cache.api.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/salesforce-cache.php' => config_path('salesforce-cache.php'),
            ], 'salesforce-cache-config');

            $this->commands([
                InvalidateCacheCommand::class,
                CacheStatusCommand::class,
            ]);
        }
    }
}
