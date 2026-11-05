# daikazu/eloquent-salesforce-cache

> **Beta** — This package is under active development. APIs may change before the stable 1.0 release. Please report issues on [GitHub](https://github.com/daikazu/eloquent-salesforce-cache/issues).

Redis-backed caching layer for `daikazu/eloquent-salesforce-objects`. Transparently caches all SOQL queries and provides surgical cache invalidation via Artisan commands, HTTP API endpoints, and a programmatic service — with zero required changes to existing models.

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Architecture Overview](#architecture-overview)
  - [Layer 1: Automatic Adapter Caching](#layer-1-automatic-adapter-caching)
  - [Layer 2: Opt-In Per-Record Tagging](#layer-2-opt-in-per-record-tagging)
- [Configuration Reference](#configuration-reference)
- [Cache Invalidation](#cache-invalidation)
  - [Programmatic Invalidation](#programmatic-invalidation)
  - [Artisan Commands](#artisan-commands)
  - [HTTP API Endpoints](#http-api-endpoints)
- [API Authentication](#api-authentication)
- [Events](#events)
- [Error Handling and Graceful Degradation](#error-handling-and-graceful-degradation)
- [Logging](#logging)

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ^8.4 |
| Laravel | ^12.0 or ^13.0 |
| daikazu/eloquent-salesforce-objects | ^2.0.1 |
| Redis | Any version supporting cache tags |

Redis is required. The Laravel cache driver must be set to `redis` (or another tagged-cache-compatible driver) for the store this package targets. Laravel's file and database cache drivers do not support cache tags.

---

## Installation

**1. Require the package.**

```bash
composer require daikazu/eloquent-salesforce-cache
```

The service provider is registered automatically via Laravel package discovery.

**2. Publish the configuration file.**

```bash
php artisan vendor:publish --tag=salesforce-cache-config
```

This creates `config/salesforce-cache.php` in your application.

**3. Configure your environment.**

Add the following to your `.env` file:

```dotenv
# Required: the API key used to authenticate cache invalidation requests
SALESFORCE_CACHE_API_KEY=your-secret-key-here

# Optional overrides (shown with defaults)
SALESFORCE_CACHE_STORE=redis
SALESFORCE_CACHE_ENABLED=true
SALESFORCE_CACHE_TTL=1800
SALESFORCE_CACHE_API_ENABLED=true
SALESFORCE_CACHE_LOG_ENABLED=false
```

**4. Verify Redis is configured.**

Ensure your `config/database.php` has a Redis connection defined, and that `config/cache.php` references it:

```php
// config/cache.php
'redis' => [
    'driver'     => 'redis',
    'connection' => 'default',
    'lock_connection' => 'default',
],
```

---

## Quick Start

Once installed, caching is active immediately. Every SOQL query executed through `daikazu/eloquent-salesforce-objects` is cached automatically — no model changes are required.

```php
// This query result is cached on first execution and served from Redis on subsequent calls.
$opportunities = Opportunity::all();

// Write operations automatically invalidate the relevant object cache.
$opportunity = Opportunity::find('0061a00000AbCdEfG');
$opportunity->update(['StageName' => 'Closed Won']);
// Cache for the Opportunity object type is now flushed.
```

To enable surgical per-record invalidation, add the `CachesSalesforceQueries` trait to any model:

```php
use Daikazu\EloquentSalesforceCache\Concerns\CachesSalesforceQueries;

class Opportunity extends SalesforceObject
{
    use CachesSalesforceQueries;

    protected int $cacheTtl = 3600;

    protected array $trackedRelationships = [
        'lineItems',
        'payments',
    ];
}
```

### Automatic Cascade Invalidation

When a child object is mutated, the package can automatically invalidate the specific parent records that contain stale related data — no manual invalidation calls required.

Add `$invalidatesObjects` to any model using the `CachesSalesforceQueries` trait:

```php
class OpportunityLineItem extends SalesforceObject
{
    use CachesSalesforceQueries;

    protected array $invalidatesObjects = ['Opportunity'];
}
```

Now when a LineItem is created, updated, or deleted, the specific parent Opportunity records tracked in the registry are surgically invalidated. Other Opportunity cache entries are untouched.

**How it works:**

1. The model declares which object types to cascade to via `$invalidatesObjects`.
2. When relationships are accessed, the package tracks parent-child mappings in Redis (via `$trackedRelationships`).
3. On mutation, the adapter looks up the child record's parents in the reverse registry and invalidates only those specific parent records.

**Requirements:**
- The parent model must use `CachesSalesforceQueries` with `$trackedRelationships` that include the child relationship — this is how the parent-child mapping gets registered.
- If no parent records are found in the registry (the relationship was never queried/cached), no cascade happens.

---

## Architecture Overview

The package implements a two-layer caching strategy. Layer 1 operates automatically at the adapter level. Layer 2 is opt-in per model and enables granular, record-level invalidation.

### Layer 1: Automatic Adapter Caching

`CachedSalesforceAdapter` extends `SalesforceAdapter` and overrides `query()` and `queryAll()`. It wraps every SOQL call in a Redis-backed cache lookup before delegating to Salesforce.

**How a cache read works:**

1. The SOQL string is examined to extract the Salesforce object name from the `FROM` clause.
2. If the object is excluded or caching is disabled, the query passes through to Salesforce directly.
3. The SOQL string is hashed (`md5`) to produce a cache key in the format `sf_cache:{type}:{hash}`.
4. The cache is checked using tags `['salesforce', '{ObjectName}']`, plus any objects read by subqueries.
5. On a cache miss, a distributed lock (`sf_lock:{cacheKey}`) is acquired to prevent cache stampede — only one process queries Salesforce; any process that can't get the lock queries Salesforce directly without caching the result.
6. The result is stored with the configured TTL. A partial result with a `nextRecordsUrl` (more than one page of records) is not cached, because Salesforce expires its query locator.

**Write-through invalidation:**

When any mutation method is called (`create`, `update`, `delete`, `upsert`, `bulkCreate`, `bulkUpdate`, `bulkUpsert`, `bulkDelete`), the adapter executes the operation first and then flushes the tags `['salesforce', '{ObjectName}']`. Flushing those tags together clears every entry under `salesforce`, so a write clears the whole Salesforce cache, not only that object's queries.

**Subqueries:**

`with()` loads `hasMany` / `hasOne` children in one query: `select Id, (select Id from Contacts) from Account`. The adapter tags that entry with both objects, using describe metadata to turn the relationship name `Contacts` into the object `Contact`, and registers the child records too. Invalidating `Account` or `Contact`, at object or record level, clears it. Semi-joins (`where Id in (select AccountId from Contact)`) work the same way.

If any object in the query is in `exclude_objects`, or the relationship names can't be resolved, the query goes to Salesforce uncached.

**Cache key format:**

```
sf_cache:query:{md5_of_soql_string}
sf_cache:queryAll:{md5_of_soql_string}
sf_cache:query:{md5_of_soql_string}:Account,Contact    (query with subqueries)
```

**Cache tags:**

```
['salesforce', 'Opportunity']
['salesforce', 'Account', 'Contact']    (query with subqueries)
```

`CacheInvalidator::invalidateAll()` clears all Salesforce cache. `CacheInvalidator::invalidateObject('Opportunity')` clears only entries tagged `Opportunity`, including queries on other objects that read Opportunity through a subquery.

Parent fields read with dot notation (`select Account.Name from Contact`) are not tracked. After an external change to the Account, invalidate `Contact` as well.

### Layer 2: Opt-In Per-Record Tagging

The `CachesSalesforceQueries` trait adds per-record tracking on top of Layer 1. When a model using this trait is hydrated from a query, its record ID is registered in the `TagRegistry` — a Redis-backed set that maps `{ObjectType}:{Id}` to the cache keys that contain that record.

This enables three capabilities not available at Layer 1:

- **Record-level invalidation**: Flush only the cache entries that contain a specific record ID, leaving all other cached queries intact.
- **Relationship tracking**: When a related model is accessed, the relationship is registered in the `TagRegistry`. Calling `invalidateCacheWithRelationships()` cascades invalidation to all related records automatically.
- **External invalidation**: Because the API endpoint and `CacheInvalidator` service work with object/ID pairs, external systems (such as Salesforce outbound messages) can trigger targeted invalidation of specific records without knowing which cache keys are involved.

**TagRegistry internals:**

The registry uses Redis sets with the following key structure:

```
{prefix}:{object}:{id}          → Set of cache keys containing this record
{prefix}:rel:{object}:{id}      → Set of "{childObject}:{childId}" relationship identifiers
{prefix}:objects                → Set of all tracked object type names
{prefix}:records:{object}       → Set of tracked record IDs for an object type
```

Registry keys expire at 2x the configured cache TTL to ensure they outlive the cache entries they describe.

**Trait properties (all optional):**

| Property | Type | Default | Description |
|---|---|---|---|
| `$cacheable` | `bool` | `true` | Set to `false` to exclude this model from Layer 2 tracking |
| `$cacheTtl` | `int` | `1800` | Per-model TTL in seconds (informational; does not override Layer 1 TTL) |
| `$trackedRelationships` | `array` | `[]` (all) | Relationship method names to track. Empty array tracks all relationships |
| `$invalidatesObjects` | `array` | `[]` | Object types to cascade-invalidate when this model is mutated |

---

## Configuration Reference

Published to `config/salesforce-cache.php`.

### Cache Settings

| Key | Type | Default | Env Variable | Description |
|---|---|---|---|---|
| `enabled` | `bool` | `true` | `SALESFORCE_CACHE_ENABLED` | Master switch. When `false`, `CachedSalesforceAdapter` is not bound and all queries pass through to Salesforce directly. |
| `store` | `string` | `'redis'` | `SALESFORCE_CACHE_STORE` | The Laravel cache store to use. Must support cache tags. |
| `ttl` | `int` | `1800` | `SALESFORCE_CACHE_TTL` | Default cache lifetime in seconds (30 minutes). |
| `exclude_objects` | `array` | `[]` | — | Salesforce object names to bypass caching entirely. |
| `registry_prefix` | `string` | `'sf_cache_registry'` | — | Redis key prefix for the per-record tag registry. |
| `log_enabled` | `bool` | `false` | `SALESFORCE_CACHE_LOG_ENABLED` | Whether to log cache hits, misses, invalidations, and failures. |
| `log_channel` | `string\|null` | `null` | `SALESFORCE_CACHE_LOG_CHANNEL` | Laravel log channel. When `null`, uses the default channel. |
| `model_paths` | `array\|null` | `null` | — | Directories to scan for Salesforce models. When `null`, scans all declared classes. |

Example — excluding specific objects:

```php
'exclude_objects' => [
    'Task',
    'ActivityHistory',
],
```

### `api` Section

Controls the HTTP cache invalidation endpoints.

| Key | Type | Default | Env Variable | Description |
|---|---|---|---|---|
| `api.enabled` | `bool` | `true` | `SALESFORCE_CACHE_API_ENABLED` | Whether to register the invalidation HTTP routes. |
| `api.prefix` | `string` | `'api/salesforce-cache'` | — | URL prefix for all invalidation routes. |
| `api.middleware` | `array` | `['api']` | — | Laravel middleware applied to all invalidation routes. |
| `api.api_key_header` | `string` | `'X-Salesforce-Cache-Key'` | — | HTTP header name for API key authentication. |
| `api.api_key` | `string\|null` | `null` | `SALESFORCE_CACHE_API_KEY` | The expected API key value. If empty, all requests are rejected (fail closed). |

---

## Cache Invalidation

### Programmatic Invalidation

Resolve `CacheInvalidator` from the container and call one of its methods directly. All methods are safe to call even when Redis is unavailable — failures are logged and never thrown.

```php
use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;

$invalidator = app(CacheInvalidator::class);
```

**`invalidateRecord(string $object, string $id): void`**

Flushes all cached queries that include the specified record. Also removes the record's entry from the tag registry.

```php
$invalidator->invalidateRecord('Opportunity', '0061a00000AbCdEfG');
```

**`invalidateRecordWithRelationships(string $object, string $id): void`**

Flushes the record and cascades to all related records tracked in the registry (populated by the `CachesSalesforceQueries` trait on models with `$trackedRelationships` defined).

```php
$invalidator->invalidateRecordWithRelationships('Opportunity', '0061a00000AbCdEfG');
```

**`invalidateObject(string $object): void`**

Flushes all cached queries for a given Salesforce object type. Equivalent to the write-through invalidation that happens automatically on mutations.

```php
$invalidator->invalidateObject('Opportunity');
```

**`invalidateMany(array $records): void`**

Flushes multiple records in a single call. Each element must be an associative array with `object` and `id` keys.

```php
$invalidator->invalidateMany([
    ['object' => 'Opportunity', 'id' => '0061a00000AbCdEfG'],
    ['object' => 'OpportunityLineItem', 'id' => '00k1a00000XyZwVu'],
]);
```

**`invalidateAll(): void`**

Flushes the entire `salesforce` cache tag and clears all registry entries. Use with caution in production.

```php
$invalidator->invalidateAll();
```

**Trait-level invalidation methods:**

When using the `CachesSalesforceQueries` trait, models also expose the following instance and static methods:

```php
// Instance method — invalidates this specific record
$opportunity->invalidateCache();

// Instance method — invalidates this record and all tracked related records
$opportunity->invalidateCacheWithRelationships();

// Static method — invalidates a record by ID
Opportunity::invalidateCacheFor('0061a00000AbCdEfG');

// Static method — invalidates multiple records by ID
Opportunity::invalidateCacheForMany(['0061a00000AbCdEfG', '0061a00000HiJkLm']);

// Static method — flushes all cached queries for this object type
Opportunity::flushAllCache();
```

---

### Artisan Commands

**`salesforce-cache:invalidate`**

Invalidates cache entries. Accepts flags for non-interactive use or runs an interactive prompt when called without flags.

```
Description:
  Invalidate Salesforce cache entries

Usage:
  salesforce-cache:invalidate [options]

Options:
  --record=*             Record to invalidate (format: ObjectType:Id, repeatable)
  --object=              Flush all cache for an object type
  --with-relationships   Cascade invalidation to related records (used with --record)
  --all                  Flush all Salesforce cache
```

Examples:

```bash
# Invalidate a single record
php artisan salesforce-cache:invalidate --record=Opportunity:0061a00000AbCdEfG

# Invalidate multiple records in one call
php artisan salesforce-cache:invalidate \
  --record=Opportunity:0061a00000AbCdEfG \
  --record=OpportunityLineItem:00k1a00000XyZwVu

# Invalidate a record and all its tracked related records
php artisan salesforce-cache:invalidate \
  --record=Opportunity:0061a00000AbCdEfG \
  --with-relationships

# Flush all cache for a specific object type
php artisan salesforce-cache:invalidate --object=Opportunity

# Flush all Salesforce cache (prompts for confirmation)
php artisan salesforce-cache:invalidate --all

# Flush all Salesforce cache without confirmation (useful in CI/scripts)
php artisan salesforce-cache:invalidate --all --no-interaction
```

**`salesforce-cache:status`**

Displays the current configuration and a summary of tracked objects in the Layer 2 registry.

```bash
php artisan salesforce-cache:status
```

Example output:

```
 INFO  Salesforce Cache Status

 ------------------- ---------
  Setting             Value
 ------------------- ---------
  Cache Store         redis
  Caching             Enabled
  Default TTL         1800s
  API Endpoints       Enabled
  Auth Header         X-Salesforce-Cache-Key
 ------------------- ---------

 INFO  Tracked Objects (Layer 2)

 ----------------------- -----------------
  Object Type             Tracked Records
 ----------------------- -----------------
  Opportunity             42
  OpportunityLineItem     187
 ----------------------- -----------------
```

---

### HTTP API Endpoints

All endpoints are registered under the configured prefix (default: `api/salesforce-cache`) and protected by the `VerifyInvalidationRequest` middleware. See [API Authentication](#api-authentication) for details on securing these endpoints.

Full documentation of the API, including curl examples, webhook integration, and authentication setup, is available in [docs/invalidation-api.md](docs/invalidation-api.md).

| Method | Path | Description |
|---|---|---|
| `POST` | `/api/salesforce-cache/invalidate` | Invalidate one or more specific records |
| `POST` | `/api/salesforce-cache/invalidate/object` | Flush all cache for an object type |
| `POST` | `/api/salesforce-cache/flush` | Flush all Salesforce cache |
| `GET` | `/api/salesforce-cache/status` | Return cache configuration and tracked object summary |

---

## API Authentication

All HTTP endpoints are protected by the `VerifyInvalidationRequest` middleware, which verifies an API key sent via HTTP header using a timing-safe comparison (`hash_equals`).

```php
// config/salesforce-cache.php
'api' => [
    'api_key_header' => 'X-Salesforce-Cache-Key',
    'api_key'        => env('SALESFORCE_CACHE_API_KEY'),
],
```

```dotenv
SALESFORCE_CACHE_API_KEY=a-long-random-string
```

If `SALESFORCE_CACHE_API_KEY` is empty or unset, all requests are rejected (fail closed).

---

## Events

### `Daikazu\EloquentSalesforceCache\Events\CacheInvalidated`

Dispatched after every cache invalidation operation, regardless of whether it was triggered by the API, an Artisan command, the `CacheInvalidator` service, or an automatic write-through invalidation from the adapter.

**Properties:**

| Property | Type | Description |
|---|---|---|
| `$object` | `string` | Salesforce object type (e.g., `'Opportunity'`). Value is `'*'` when scope is `all`. |
| `$ids` | `string[]` | Array of invalidated record IDs. Empty array when scope is `object` or `all`. |
| `$scope` | `string` | Invalidation scope: `'record'`, `'object'`, or `'all'`. |

**Registering a listener:**

In your `EventServiceProvider`:

```php
use Daikazu\EloquentSalesforceCache\Events\CacheInvalidated;
use App\Listeners\LogCacheInvalidation;

protected $listen = [
    CacheInvalidated::class => [
        LogCacheInvalidation::class,
    ],
];
```

Example listener:

```php
namespace App\Listeners;

use Daikazu\EloquentSalesforceCache\Events\CacheInvalidated;
use Illuminate\Support\Facades\Log;

class LogCacheInvalidation
{
    public function handle(CacheInvalidated $event): void
    {
        Log::info('Salesforce cache invalidated', [
            'object' => $event->object,
            'ids'    => $event->ids,
            'scope'  => $event->scope,
        ]);
    }
}
```

Using a closure listener in `AppServiceProvider::boot()`:

```php
use Daikazu\EloquentSalesforceCache\Events\CacheInvalidated;
use Illuminate\Support\Facades\Event;

Event::listen(CacheInvalidated::class, function (CacheInvalidated $event): void {
    // Notify a monitoring system, invalidate a secondary cache, etc.
});
```

---

## Error Handling and Graceful Degradation

All cache operations in this package are wrapped in try/catch blocks and degrade gracefully when Redis is unavailable. The application continues to function — it simply makes live requests to Salesforce instead of serving cached results.

Specific behaviors:

- **Cache read failure**: Returns `null` (treated as a cache miss), causing a live Salesforce query.
- **Cache write failure**: The Salesforce response is returned to the caller; the failure is logged at `warning` level if logging is enabled.
- **Lock acquisition failure**: The process queries Salesforce directly without caching. Stampede protection is best-effort.
- **Lock release failure**: The lock auto-expires (10-second TTL) and is ignored.
- **Invalidation failure**: The write operation already succeeded before invalidation was attempted. The failure is logged; no exception is thrown.
- **Registry failure**: Tag registration silently degrades. Layer 1 object-level caching continues to function.

No exception from any cache operation bubbles up to the caller. If you need to observe failures, enable logging (`SALESFORCE_CACHE_LOG_ENABLED=true`) or listen to application log events.

---

## Logging

Cache events are logged at the `debug` level; failures are logged at the `warning` level. All log messages are prefixed with `[SalesforceCache]`.

```dotenv
SALESFORCE_CACHE_LOG_ENABLED=true
SALESFORCE_CACHE_LOG_CHANNEL=stack
```

Logged events include:

| Event | Level | Context |
|---|---|---|
| Cache hit | `debug` | `key`, `object`, `type` |
| Cache miss | `debug` | `key`, `object`, `type` |
| Cache invalidated | `debug` | `object`, `tags` |
| Cache read failed | `warning` | `error` |
| Cache write failed | `warning` | `error`, `object` |
| Cache lock unavailable | `warning` | `error` |
| Invalidation failed | `warning` | `error`, `object` |
| Registry operation failed | `warning` | `error` |

---

## License

MIT
