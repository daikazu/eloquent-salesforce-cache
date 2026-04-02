<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cache Store & TTL
    |--------------------------------------------------------------------------
    |
    | The cache store must support tags (Redis, Memcached, etc.).
    | File and database drivers will not work.
    |
    */

    'enabled' => env('SALESFORCE_CACHE_ENABLED', true),
    'store'   => env('SALESFORCE_CACHE_STORE', 'redis'),
    'ttl'     => (int) env('SALESFORCE_CACHE_TTL', 1800), // seconds (default 30 min)

    // Salesforce objects that should bypass caching entirely (e.g. ['Task', 'ActivityHistory'])
    'exclude_objects' => [],

    /*
    |--------------------------------------------------------------------------
    | Registry
    |--------------------------------------------------------------------------
    |
    | Redis key prefix for the per-record tag registry (Layer 2).
    | The registry maps record IDs to the cache keys that contain them,
    | enabling surgical per-record invalidation.
    |
    */

    'registry_prefix' => 'sf_cache_registry',

    // Optional: limit model discovery to specific directories for faster scanning.
    // When null, scans all declared classes.
    'model_paths' => null,

    /*
    |--------------------------------------------------------------------------
    | Invalidation API
    |--------------------------------------------------------------------------
    |
    | HTTP endpoints for external cache invalidation (e.g. Salesforce webhooks).
    | Protected by API key authentication via the configured header.
    |
    */

    'api' => [
        'enabled'        => env('SALESFORCE_CACHE_API_ENABLED', true),
        'prefix'         => 'api/salesforce-cache',
        'middleware'     => ['api'],
        'api_key_header' => 'X-Salesforce-Cache-Key',
        'api_key'        => env('SALESFORCE_CACHE_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, logs cache hits, misses, invalidations, and failures
    | at debug/warning level. All messages are prefixed with [SalesforceCache].
    |
    */

    'log_enabled' => env('SALESFORCE_CACHE_LOG_ENABLED', false),
    'log_channel' => env('SALESFORCE_CACHE_LOG_CHANNEL'),
];
