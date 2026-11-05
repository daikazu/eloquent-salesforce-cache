# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview

`daikazu/eloquent-salesforce-cache` is a Laravel package that provides Redis-backed caching for `daikazu/eloquent-salesforce-objects`. It lives in the host app at `packages/eloquent-salesforce-cache/` and is symlinked via Composer path repository.

Namespace: `Daikazu\EloquentSalesforceCache\`

## Architecture

Two-layer caching strategy:

**Layer 1 — Adapter-level (automatic):** `CachedSalesforceAdapter` extends `SalesforceAdapter` (must extend, not decorate — `SOQLBuilder`/`SOQLConnection` type-hint the concrete class). Caches all `query()`/`queryAll()` calls. Write-through invalidation on all mutations. Bound as `SalesforceAdapter::class` singleton via a `booting()` callback (runs after all providers register, since alphabetical package discovery means "cache" loads before "objects").

**Layer 2 — Model-level (opt-in):** `CachesSalesforceQueries` trait on models enables per-record tracking via `TagRegistry` and relationship dependency tracking via `getRelationValue()` override. Provides instance and static invalidation methods. Models can declare `$invalidatesObjects` for automatic cascade invalidation of parent records on mutation.

**Key classes:**
- `src/Adapters/CachedSalesforceAdapter.php` — Layer 1 core, stampede protection via distributed locks
- `src/Concerns/CachesSalesforceQueries.php` — Layer 2 trait for models
- `src/Support/TagRegistry.php` — Redis-set-backed record-to-cache-key mapping
- `src/Support/CacheKey.php` — Cache keys and tags; multi-object keys carry the objects they were tagged with
- `src/Support/ModelDiscovery.php` — Auto-discovers models and builds cascade invalidation map
- `src/Services/CacheInvalidator.php` — Unified invalidation entry point (API, CLI, programmatic)
- `src/EloquentSalesforceCacheServiceProvider.php` — Service provider with booting-callback binding pattern

## Common Commands

```bash
# Run tests (from host app root, not package dir)
cd /Users/mikewall/Sites/tjm-portal
php -d memory_limit=512M vendor/bin/pest --compact tests/

# Run package-specific tests (when test dir exists)
php -d memory_limit=512M vendor/bin/pest --compact packages/eloquent-salesforce-cache/tests/

# Lint PHP
vendor/bin/pint --dirty --format agent

# Check cache status
php artisan salesforce-cache:status

# Invalidate cache
php artisan salesforce-cache:invalidate --record=ObjectType:Id
php artisan salesforce-cache:invalidate --object=ObjectType
php artisan salesforce-cache:invalidate --all --no-interaction
```

## Critical Design Decisions

- **Extends, not implements:** `CachedSalesforceAdapter` MUST extend `SalesforceAdapter` because `SOQLBuilder` type-hints the concrete class. Using `AdapterInterface` was the bug in the old host-app implementation that made caching completely broken.
- **Booting callback:** The `SalesforceAdapter` singleton binding uses `$this->app->booting()` instead of direct `register()` binding because package discovery is alphabetical — the cache package registers before the objects package, so a direct binding would be overwritten.
- **Graceful degradation:** All cache operations are wrapped in try/catch. Redis failures never bubble up — queries fall through to live Salesforce calls.
- **Cache tags require Redis:** The `redis` (or other tag-compatible) cache driver is required. File/database drivers do not support tags.

## Config

Published to `config/salesforce-cache.php`. Key env vars: `SALESFORCE_CACHE_ENABLED`, `SALESFORCE_CACHE_TTL`, `SALESFORCE_CACHE_STORE`, `SALESFORCE_CACHE_API_KEY`, `SALESFORCE_CACHE_LOG_ENABLED`.

## API Endpoints

Registered under `api/salesforce-cache/` prefix, protected by `VerifyInvalidationRequest` middleware (API key via `X-Salesforce-Cache-Key` header). Full API docs in `docs/invalidation-api.md`.
