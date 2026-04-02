<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache\Tests;

use Daikazu\EloquentSalesforceCache\EloquentSalesforceCacheServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Override;

abstract class TestCase extends OrchestraTestCase
{
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            EloquentSalesforceCacheServiceProvider::class,
        ];
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('salesforce-cache.enabled', true);
        $app['config']->set('salesforce-cache.store', 'array');
        $app['config']->set('salesforce-cache.ttl', 1800);
        $app['config']->set('salesforce-cache.registry_prefix', 'sf_cache_registry');
        $app['config']->set('salesforce-cache.api.enabled', true);
        $app['config']->set('salesforce-cache.api.api_key_header', 'X-Salesforce-Cache-Key');
        $app['config']->set('salesforce-cache.api.api_key', 'test-api-key');
        $app['config']->set('salesforce-cache.log_enabled', false);
    }
}
