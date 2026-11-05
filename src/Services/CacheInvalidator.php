<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache\Services;

use Daikazu\EloquentSalesforceCache\Enums\InvalidationScope;
use Daikazu\EloquentSalesforceCache\Events\CacheInvalidated;
use Daikazu\EloquentSalesforceCache\Support\CacheKey;
use Daikazu\EloquentSalesforceCache\Support\TagRegistry;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single entry point for all cache invalidation.
 *
 * All operations degrade gracefully — Redis failures are logged
 * but never thrown, since invalidation failure should not crash the app.
 */
class CacheInvalidator
{
    public function __construct(
        private readonly TagRegistry $registry,
    ) {}

    /**
     * Invalidate cache for a specific record.
     *
     * Looks up the actual cache keys that contain this record
     * via the TagRegistry, then deletes them from the tagged store.
     */
    public function invalidateRecord(string $object, string $id): void
    {
        try {
            $this->forgetRecordCacheKeys($object, $id);
            CacheInvalidated::dispatch($object, [$id], InvalidationScope::Record);
        } catch (Throwable $e) {
            $this->logFailure('invalidateRecord', $e, $object);
        }
    }

    /**
     * Invalidate cache for a record and all its directly tracked relationships.
     *
     * Cascade is one level deep: parent -> children only.
     * Nested relationships (child -> grandchild) are not followed.
     */
    public function invalidateRecordWithRelationships(string $object, string $id): void
    {
        try {
            $related = $this->registry->getRelatedRecords($object, $id);

            $this->forgetRecordCacheKeys($object, $id);

            $relatedRecords = [];

            foreach ($related as $relatedTag) {
                [$relObject, $relId] = explode(':', $relatedTag, 2);
                $this->forgetRecordCacheKeys($relObject, $relId);
                $relatedRecords[] = ['object' => $relObject, 'id' => $relId];
            }

            CacheInvalidated::dispatch($object, [$id], InvalidationScope::Record, $relatedRecords);
        } catch (Throwable $e) {
            $this->logFailure('invalidateRecordWithRelationships', $e, $object);
        }
    }

    /**
     * Invalidate all cached queries for a Salesforce object type.
     */
    public function invalidateObject(string $object): void
    {
        $resolved = $this->resolveObjectName($object);

        try {
            $this->cacheStore()->tags([$resolved])->flush();
            CacheInvalidated::dispatch($resolved, [], InvalidationScope::Object);
        } catch (Throwable $e) {
            $this->logFailure('invalidateObject', $e, $resolved);
        }
    }

    /**
     * Invalidate all Salesforce cache.
     */
    public function invalidateAll(): void
    {
        try {
            $this->cacheStore()->tags(['salesforce'])->flush();
            $this->registry->flush();
            CacheInvalidated::dispatch('*', [], InvalidationScope::All);
        } catch (Throwable $e) {
            $this->logFailure('invalidateAll', $e);
        }
    }

    /**
     * Invalidate multiple records at once.
     *
     * Groups records by object type and dispatches one event per object type
     * rather than one event per record.
     *
     * @param  array<array{object: string, id: string}>  $records
     */
    public function invalidateMany(array $records): void
    {
        $grouped = [];

        foreach ($records as $record) {
            try {
                $this->forgetRecordCacheKeys($record['object'], $record['id']);
                $grouped[$record['object']][] = $record['id'];
            } catch (Throwable $e) {
                $this->logFailure('invalidateMany', $e, $record['object']);
            }
        }

        foreach ($grouped as $object => $ids) {
            CacheInvalidated::dispatch($object, $ids, InvalidationScope::Record);
        }
    }

    /**
     * Look up cache keys from the TagRegistry for a record and delete them
     * from the tagged cache store, then clean up the registry entry.
     *
     * Resolves the object name by trying the alternate form (with/without __c)
     * if the given name has no registry entries.
     */
    private function forgetRecordCacheKeys(string $object, string $id): void
    {
        [$resolved, $cacheKeys] = $this->resolveRecordKeys($object, $id);

        foreach ($cacheKeys as $cacheKey) {
            $this->cacheStore()->tags(CacheKey::tagsForKey($cacheKey, $resolved))->forget($cacheKey);
        }

        $this->registry->remove($resolved, $id);
    }

    /**
     * Resolve the correct object name for a record by checking the registry.
     *
     * SOQL FROM clauses use the API name (e.g., "Opportunity"), but external
     * callers may use the suffixed form (e.g., "Opportunity__c"). This method
     * tries both forms and returns whichever has registry entries.
     *
     * @return array{0: string, 1: string[]} Resolved object name and its cache keys
     */
    private function resolveRecordKeys(string $object, string $id): array
    {
        $cacheKeys = $this->registry->getKeysForRecord($object, $id);

        if ($cacheKeys !== []) {
            return [$object, $cacheKeys];
        }

        $alternate = $this->alternateObjectName($object);
        $altKeys = $this->registry->getKeysForRecord($alternate, $id);

        if ($altKeys !== []) {
            return [$alternate, $altKeys];
        }

        return [$object, []];
    }

    /**
     * Resolve the correct object name for object-level invalidation
     * by checking tracked object types in the registry.
     */
    private function resolveObjectName(string $object): string
    {
        $trackedObjects = $this->registry->getTrackedObjectTypes();

        if (in_array($object, $trackedObjects, true)) {
            return $object;
        }

        $alternate = $this->alternateObjectName($object);

        if (in_array($alternate, $trackedObjects, true)) {
            return $alternate;
        }

        return $object;
    }

    /**
     * Get the alternate form of a Salesforce object name (with/without __c suffix).
     */
    private function alternateObjectName(string $object): string
    {
        return str_ends_with($object, '__c')
            ? substr($object, 0, -3)
            : $object . '__c';
    }

    private function cacheStore(): CacheRepository
    {
        /** @var CacheRepository $store */
        $store = Cache::store(config('salesforce-cache.store'));

        return $store;
    }

    private function logFailure(string $operation, Throwable $e, ?string $object = null): void
    {
        if (config('salesforce-cache.log_enabled', false)) {
            $channel = config('salesforce-cache.log_channel');
            $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();
            $logger->warning("[SalesforceCache] CacheInvalidator {$operation} failed", [
                'error'  => $e->getMessage(),
                'object' => $object,
            ]);
        }
    }
}
