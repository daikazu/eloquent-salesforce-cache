<?php

declare(strict_types=1);

use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;
use Daikazu\EloquentSalesforceCache\Support\TagRegistry;

it('registers TagRegistry as singleton', function (): void {
    $a = app(TagRegistry::class);
    $b = app(TagRegistry::class);

    expect($a)->toBe($b);
});

it('registers CacheInvalidator as singleton', function (): void {
    $a = app(CacheInvalidator::class);
    $b = app(CacheInvalidator::class);

    expect($a)->toBe($b);
});

it('merges package config', function (): void {
    expect(config('salesforce-cache.enabled'))->not->toBeNull();
    expect(config('salesforce-cache.store'))->not->toBeNull();
    expect(config('salesforce-cache.ttl'))->not->toBeNull();
    expect(config('salesforce-cache.api'))->toBeArray();
});

it('registers api routes when enabled', function (): void {
    config()->set('salesforce-cache.api.enabled', true);

    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->pluck('uri')
        ->filter(fn (string $uri): bool => str_contains($uri, 'salesforce-cache'));

    expect($routes)->not->toBeEmpty();
});
