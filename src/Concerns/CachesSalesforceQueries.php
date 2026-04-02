<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache\Concerns;

use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;
use Daikazu\EloquentSalesforceCache\Support\TagRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * Opt-in trait for Salesforce models that enables per-record cache invalidation
 * and relationship dependency tracking.
 *
 * Cache key registration is handled by the CachedSalesforceAdapter when it stores
 * query results. This trait provides convenience invalidation methods and tracks
 * relationship dependencies for cascade invalidation.
 *
 * Properties (all optional):
 *   protected bool $cacheable = true;
 *   protected int $cacheTtl = 1800;
 *   protected array $trackedRelationships = [];
 */
trait CachesSalesforceQueries
{
    public function isCacheable(): bool
    {
        return property_exists($this, 'cacheable') ? $this->cacheable : true;
    }

    public function getCacheTtl(): int
    {
        return property_exists($this, 'cacheTtl') ? $this->cacheTtl : 1800;
    }

    /**
     * @return string[]
     */
    public function getTrackedRelationships(): array
    {
        return property_exists($this, 'trackedRelationships') ? $this->trackedRelationships : [];
    }

    /**
     * Get the Salesforce object types whose cache should be invalidated
     * when this object is mutated.
     *
     * @return string[]
     */
    public function getInvalidatesObjects(): array
    {
        return property_exists($this, 'invalidatesObjects') ? $this->invalidatesObjects : [];
    }

    /**
     * Override relationship loading to track dependencies in the registry.
     */
    public function getRelationValue($key): mixed
    {
        $value = parent::getRelationValue($key);

        if ($value === null || ! $this->isCacheable()) {
            return $value;
        }

        // Check if this relationship should be tracked
        $tracked = $this->getTrackedRelationships();
        if (! empty($tracked) && ! in_array($key, $tracked, true)) {
            return $value;
        }

        // TagRegistry handles its own error handling — safe to call directly
        $registry = app(TagRegistry::class);

        if ($value instanceof Model) {
            $registry->registerRelationship(
                parentObject: $this->getTable(),
                parentId: (string) $this->getKey(),
                childObject: $value->getTable(),
                childId: (string) $value->getKey(),
            );
        } elseif (is_iterable($value)) {
            foreach ($value as $related) {
                if ($related instanceof Model) {
                    $registry->registerRelationship(
                        parentObject: $this->getTable(),
                        parentId: (string) $this->getKey(),
                        childObject: $related->getTable(),
                        childId: (string) $related->getKey(),
                    );
                }
            }
        }

        return $value;
    }

    /**
     * Invalidate cache for a specific record by ID.
     */
    public static function invalidateCacheFor(string $id): void
    {
        $object = (new static)->getTable();
        app(CacheInvalidator::class)->invalidateRecord($object, $id);
    }

    /**
     * Invalidate cache for multiple record IDs.
     *
     * @param  string[]  $ids
     */
    public static function invalidateCacheForMany(array $ids): void
    {
        foreach ($ids as $id) {
            static::invalidateCacheFor($id);
        }
    }

    /**
     * Flush all cached queries for this model type.
     */
    public static function flushAllCache(): void
    {
        $object = (new static)->getTable();
        app(CacheInvalidator::class)->invalidateObject($object);
    }

    /**
     * Invalidate this model instance's cache.
     */
    public function invalidateCache(): void
    {
        static::invalidateCacheFor((string) $this->getKey());
    }

    /**
     * Invalidate this model instance's cache and all tracked relationships.
     */
    public function invalidateCacheWithRelationships(): void
    {
        $object = $this->getTable();
        $id = (string) $this->getKey();
        app(CacheInvalidator::class)->invalidateRecordWithRelationships($object, $id);
    }
}
