<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache\Adapters;

use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;
use Daikazu\EloquentSalesforceCache\Support\CacheKey;
use Daikazu\EloquentSalesforceCache\Support\ModelDiscovery;
use Daikazu\EloquentSalesforceCache\Support\TagRegistry;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Cache\TaggedCache;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Override;
use Throwable;

/**
 * Redis-tagged caching layer for SOQL queries with write-through invalidation
 * and stampede protection. Degrades gracefully when Redis is unavailable.
 */
class CachedSalesforceAdapter extends SalesforceAdapter
{
    /**
     * Regex pattern to extract the object name from a SOQL FROM clause.
     *
     * Matches: FROM ObjectName, FROM Namespace__ObjectName__c, etc.
     */
    private const string FROM_PATTERN = '/\bFROM\s+(\w+)/i';

    /**
     * Matches a parenthesised subquery, capturing everything after its SELECT.
     * Balances nested parentheses, so functions and deeper subqueries stay inside it.
     */
    private const string SUBQUERY_PATTERN = '/\(\s*SELECT\b(?<body>(?:[^()]++|\((?&body)\))*+)\)/i';

    /**
     * Matches a quoted SOQL string, including backslash-escaped characters.
     */
    private const string STRING_LITERAL_PATTERN = "/'(?:[^'\\\\]|\\\\.)*+'/s";

    /**
     * Execute a SOQL query with transparent caching.
     */
    #[Override]
    public function query(string $soql): array
    {
        return $this->cachedQuery('query', $soql, fn (): array => parent::query($soql));
    }

    /**
     * Execute a SOQL queryAll (includes deleted/archived) with transparent caching.
     */
    #[Override]
    public function queryAll(string $soql): array
    {
        return $this->cachedQuery('queryAll', $soql, fn (): array => parent::queryAll($soql));
    }

    /**
     * Create a record and invalidate the object's cache.
     */
    #[Override]
    public function create(string $object, array $data): array
    {
        $result = parent::create($object, $data);
        $this->safeInvalidateObject($object);

        $id = $result['id'] ?? null;
        if ($id !== null) {
            $this->safeCascadeInvalidation($object, [(string) $id]);
        }

        return $result;
    }

    /**
     * Update a record and invalidate the object's cache.
     */
    #[Override]
    public function update(string $object, string $id, array $data): bool
    {
        $result = parent::update($object, $id, $data);
        $this->safeInvalidateObject($object);
        $this->safeCascadeInvalidation($object, [$id]);

        return $result;
    }

    /**
     * Delete a record and invalidate the object's cache.
     */
    #[Override]
    public function delete(string $object, string $id): bool
    {
        $result = parent::delete($object, $id);
        $this->safeInvalidateObject($object);
        $this->safeCascadeInvalidation($object, [$id]);

        return $result;
    }

    /**
     * Upsert a record and invalidate the object's cache.
     */
    #[Override]
    public function upsert(string $object, string $externalIdField, string $externalId, array $data): array
    {
        $result = parent::upsert($object, $externalIdField, $externalId, $data);
        $this->safeInvalidateObject($object);

        $id = $result['id'] ?? null;
        if ($id !== null) {
            $this->safeCascadeInvalidation($object, [(string) $id]);
        }

        return $result;
    }

    /**
     * Bulk create records and invalidate the object's cache.
     */
    #[Override]
    public function bulkCreate(string $object, array $records, bool $allOrNone = false): array
    {
        $result = parent::bulkCreate($object, $records, $allOrNone);
        $this->safeInvalidateObject($object);
        $this->safeCascadeInvalidation($object, $this->extractIdsFromBulkResult($result));

        return $result;
    }

    /**
     * Bulk update records and invalidate the object's cache.
     */
    #[Override]
    public function bulkUpdate(string $object, array $records, bool $allOrNone = false): array
    {
        $result = parent::bulkUpdate($object, $records, $allOrNone);
        $this->safeInvalidateObject($object);
        $this->safeCascadeInvalidation($object, $this->extractIdsFromBulkResult($result));

        return $result;
    }

    /**
     * Bulk upsert records by External Id and invalidate the object's cache.
     */
    #[Override]
    public function bulkUpsert(string $object, string $externalIdField, array $records, bool $allOrNone = false): array
    {
        $result = parent::bulkUpsert($object, $externalIdField, $records, $allOrNone);
        $this->safeInvalidateObject($object);
        $this->safeCascadeInvalidation($object, $this->extractIdsFromBulkResult($result));

        return $result;
    }

    /**
     * Bulk delete records and invalidate the object's cache.
     */
    #[Override]
    public function bulkDelete(string $object, array $ids, bool $allOrNone = false): array
    {
        $result = parent::bulkDelete($object, $ids, $allOrNone);
        $this->safeInvalidateObject($object);
        $this->safeCascadeInvalidation($object, $ids);

        return $result;
    }

    /**
     * Cascade invalidation to parent records based on model discovery.
     *
     * @param  string[]  $recordIds  IDs of the records that were mutated
     */
    private function safeCascadeInvalidation(string $object, array $recordIds): void
    {
        if (! $this->isCachingEnabled() || $recordIds === []) {
            return;
        }

        try {
            $targets = app(ModelDiscovery::class)->getCascadeTargets($object);

            if ($targets === []) {
                return;
            }

            $registry = app(TagRegistry::class);
            $invalidator = app(CacheInvalidator::class);

            foreach ($recordIds as $recordId) {
                $parents = $registry->getParentRecords($object, $recordId);

                foreach ($parents as $parentTag) {
                    [$parentObject, $parentId] = explode(':', $parentTag, 2);

                    if (in_array($parentObject, $targets, true)) {
                        $invalidator->invalidateRecord($parentObject, $parentId);
                    }
                }
            }
        } catch (Throwable $e) {
            $this->log('Cascade invalidation failed', ['object' => $object, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Extract successful record IDs from a Salesforce bulk operation response.
     *
     * @return string[]
     */
    private function extractIdsFromBulkResult(array $results): array
    {
        $ids = [];

        foreach ($results as $result) {
            if (is_array($result) && ! empty($result['success']) && isset($result['id'])) {
                $ids[] = (string) $result['id'];
            }
        }

        return $ids;
    }

    /**
     * Execute a SOQL query with caching, stampede protection, and graceful degradation.
     *
     * @param  'query'|'queryAll'  $type  The query type for cache key differentiation
     * @param  callable(): array  $callback  The parent query method to call on cache miss
     */
    private function cachedQuery(string $type, string $soql, callable $callback): array
    {
        if (! $this->isCachingEnabled()) {
            return $callback();
        }

        $objectName = $this->extractObjectName($soql);

        if ($objectName === null) {
            return $callback();
        }

        try {
            $relationships = $this->extractSubqueryObjects($soql, $objectName);
        } catch (Throwable $e) {
            // Without knowing what the query reads it could not be invalidated correctly
            $this->log('Subquery resolution failed', ['object' => $objectName, 'error' => $e->getMessage()]);

            return $callback();
        }

        $objects = array_values(array_unique([$objectName, ...array_values($relationships)]));

        if (array_filter($objects, $this->isObjectExcluded(...)) !== []) {
            return $callback();
        }

        $cacheKey = $this->buildCacheKey($type, $soql, $objects);
        $tags = CacheKey::tags($objects);
        $ttl = $this->getTtl();
        $context = ['key' => $cacheKey, 'object' => $objectName, 'type' => $type];

        // 1. Try cache read
        $cached = $this->safeGet($tags, $cacheKey);

        if ($cached !== null) {
            $this->log('Cache hit', $context);
            $this->pushMonitorEvent('hit', $objectName, $soql, $cacheKey);

            return $cached;
        }

        $this->log('Cache miss', $context);
        $this->pushMonitorEvent('miss', $objectName, $soql, $cacheKey);

        // 2. Stampede protection: acquire lock so only one request queries Salesforce
        $lock = $this->acquireLock($cacheKey);

        if ($lock instanceof Lock) {
            try {
                // Double-check cache — another request may have populated it while we waited
                $cached = $this->safeGet($tags, $cacheKey);

                if ($cached !== null) {
                    return $cached;
                }

                $result = $callback();
                $this->safePut($tags, $cacheKey, $result, $ttl, $objectName, $relationships);

                return $result;
            } finally {
                $this->releaseLock($lock);
            }
        }

        // 3. Lock unavailable (Redis down or timeout) — query Salesforce directly
        return $callback();
    }

    /**
     * Read from cache, returning null on Redis failure.
     */
    private function safeGet(array $tags, string $cacheKey): mixed
    {
        try {
            return $this->taggedStore($tags)->get($cacheKey);
        } catch (Throwable $e) {
            $this->log('Cache read failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Write to cache and register cache key per record in the TagRegistry.
     */
    private function safePut(array $tags, string $cacheKey, array $result, int $ttl, string $objectName, array $relationships = []): void
    {
        // A partial page points at a query locator that Salesforce expires, so a later
        // cache hit would fail when it asked for the next page.
        if (! empty($result['nextRecordsUrl'])) {
            return;
        }

        try {
            $this->taggedStore($tags)->put($cacheKey, $result, $ttl);
        } catch (Throwable $e) {
            $this->log('Cache write failed', ['error' => $e->getMessage(), 'object' => $objectName]);

            return;
        }

        $this->registerRecordKeys($objectName, $cacheKey, $result['records'] ?? [], $relationships);
    }

    /**
     * Register the cache key against each record in the results, and against the
     * child records nested in them, for per-record invalidation.
     *
     * @param  array<string, string>  $relationships  Child relationship name => object name
     */
    private function registerRecordKeys(string $objectName, string $cacheKey, array $records, array $relationships = []): void
    {
        if (empty($records)) {
            return;
        }

        try {
            $registry = app(TagRegistry::class);

            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                if (isset($record['Id'])) {
                    $registry->register($objectName, (string) $record['Id'], $cacheKey);
                }

                foreach ($relationships as $relationship => $childObject) {
                    if (is_array($record[$relationship] ?? null)) {
                        $this->registerRecordKeys($childObject, $cacheKey, $record[$relationship], $relationships);
                    }
                }
            }
        } catch (Throwable $e) {
            $this->log('Registry write failed', ['error' => $e->getMessage(), 'object' => $objectName]);
        }
    }

    /**
     * Acquire a cache lock for stampede protection. Waits up to 5 seconds.
     * Returns null if lock cannot be acquired or Redis is unavailable.
     */
    private function acquireLock(string $cacheKey): ?Lock
    {
        try {
            $store = $this->cacheStore()->getStore();

            if (! $store instanceof LockProvider) {
                return null;
            }

            $lock = $store->lock("sf_lock:{$cacheKey}", 10);

            return $lock->get() ? $lock : null;
        } catch (Throwable $e) {
            $this->log('Cache lock unavailable', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Release a cache lock, ignoring errors (lock auto-expires).
     */
    private function releaseLock(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // Lock will auto-expire
        }
    }

    /**
     * Invalidate all cached queries for a given Salesforce object.
     * Failures are logged but never thrown — the write already succeeded.
     */
    private function safeInvalidateObject(string $object): void
    {
        if (! $this->isCachingEnabled()) {
            return;
        }

        try {
            $tags = $this->buildTags($object);
            $this->taggedStore($tags)->flush();
            $this->log('Cache invalidated', ['object' => $object, 'tags' => $tags]);
            $this->pushMonitorEvent('invalidation', $object);
        } catch (Throwable $e) {
            $this->log('Cache invalidation failed', ['object' => $object, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Extract the object a SOQL statement selects from, ignoring any subqueries.
     */
    private function extractObjectName(string $soql): ?string
    {
        $outer = preg_replace(self::SUBQUERY_PATTERN, '', $this->withoutStringLiterals($soql));

        if (preg_match(self::FROM_PATTERN, (string) $outer, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Find the objects read by a statement's subqueries, such as the child subqueries
     * that with() sends and semi-joins in a WHERE clause.
     *
     * @return array<string, string> Name used in the FROM clause => object name
     *
     * @throws Throwable When describe metadata is needed but can't be fetched
     */
    private function extractSubqueryObjects(string $soql, string $parent): array
    {
        preg_match_all(self::SUBQUERY_PATTERN, $this->withoutStringLiterals($soql), $matches);

        $objects = [];

        foreach ($matches['body'] as $body) {
            $subquery = 'SELECT' . $body;
            $name = $this->extractObjectName($subquery);

            if ($name === null) {
                continue;
            }

            [$name, $object] = $this->resolveRelationship($parent, $name);

            $objects[$name] = $object;
            $objects += $this->extractSubqueryObjects($subquery, $object);
        }

        return $objects;
    }

    /**
     * A child subquery selects from a relationship ("Contacts"), which describe metadata
     * maps to its object ("Contact"). A semi-join already selects from the object itself.
     *
     * @return array{0: string, 1: string} Relationship name as Salesforce returns it, and object name
     *
     * @throws Throwable
     */
    private function resolveRelationship(string $parent, string $name): array
    {
        foreach ($this->describe($parent)['childRelationships'] ?? [] as $relationship) {
            if (strcasecmp($relationship['relationshipName'] ?? '', $name) === 0) {
                return [$relationship['relationshipName'], $relationship['childSObject']];
            }
        }

        return [$name, $name];
    }

    /**
     * Blank out quoted strings so their contents can't be mistaken for SOQL.
     */
    private function withoutStringLiterals(string $soql): string
    {
        return (string) preg_replace(self::STRING_LITERAL_PATTERN, "''", $soql);
    }

    /**
     * Build the cache key for a given query type and SOQL string.
     *
     * @param  string[]  $objects  Every object the query reads, root object first
     */
    private function buildCacheKey(string $type, string $soql, array $objects = []): string
    {
        return CacheKey::make($type, $soql, $objects);
    }

    /**
     * Build cache tags for the given Salesforce object.
     *
     * @return string[]
     */
    private function buildTags(string $objectName): array
    {
        return CacheKey::tags([$objectName]);
    }

    /**
     * Get the configured cache store as the concrete Repository so that
     * tag-aware and lock-aware methods (tags(), lock()) are accessible.
     */
    private function cacheStore(): CacheRepository
    {
        /** @var CacheRepository $store */
        $store = Cache::store(config('salesforce-cache.store'));

        return $store;
    }

    /**
     * Get a tagged cache store instance.
     *
     * @param  string[]  $tags
     */
    private function taggedStore(array $tags): TaggedCache
    {
        return $this->cacheStore()->tags($tags);
    }

    /**
     * Check whether adapter-level caching is enabled.
     */
    private function isCachingEnabled(): bool
    {
        return (bool) config('salesforce-cache.enabled', true);
    }

    /**
     * Check whether a given object is excluded from caching.
     */
    private function isObjectExcluded(string $objectName): bool
    {
        $excluded = config('salesforce-cache.exclude_objects', []);

        return in_array($objectName, $excluded, true);
    }

    /**
     * Get the configured cache TTL in seconds.
     */
    private function getTtl(): int
    {
        return (int) config('salesforce-cache.ttl', 1800);
    }

    /**
     * Push a cache event to the API monitor Redis list for real-time visibility.
     *
     * @param  'hit'|'miss'|'invalidation'  $action
     */
    private function pushMonitorEvent(string $action, string $object, ?string $soql = null, ?string $cacheKey = null): void
    {
        if (! config('api-monitor.enabled')) {
            return;
        }

        try {
            $payload = json_encode([
                'id'           => Str::uuid()->toString(),
                'type'         => 'cache',
                'cache_action' => $action,
                'timestamp'    => now()->toISOString(),
                'object'       => $object,
                'soql'         => $soql,
                'cache_key'    => $cacheKey,
            ]);

            $redis = $this->monitorRedisConnection();
            $redis->rpush('api-monitor:events', $payload);
            $redis->ltrim('api-monitor:events', -500, -1);
            $redis->expire('api-monitor:events', 300);
        } catch (Throwable) {
            // Silently fail — this is a dev tool
        }
    }

    /**
     * Get the default Redis connection typed as PhpRedisConnection so that
     * PHPStan resolves Redis commands via its @mixin \Redis annotation.
     */
    private function monitorRedisConnection(): PhpRedisConnection
    {
        /** @var PhpRedisConnection $connection */
        $connection = Redis::connection();

        return $connection;
    }

    /**
     * Log a cache event if logging is enabled.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(string $message, array $context = []): void
    {
        if (! config('salesforce-cache.log_enabled', false)) {
            return;
        }

        $channel = config('salesforce-cache.log_channel');

        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();

        $logger->debug("[SalesforceCache] {$message}", $context);
    }
}
