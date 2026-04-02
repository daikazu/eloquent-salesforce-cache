<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache\Support;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Redis-backed registry for tracking per-record cache key mappings.
 *
 * All operations degrade gracefully — Redis failures are logged
 * but never thrown, since the registry is supplementary to caching.
 */
class TagRegistry
{
    /**
     * Register a cache key against a record identifier.
     */
    public function register(string $object, string $id, string $cacheKey): void
    {
        try {
            $registryKey = $this->recordKey($object, $id);
            $redis = $this->redis();
            $ttl = $this->ttl();

            $redis->sadd($registryKey, $cacheKey);
            $redis->expire($registryKey, $ttl);

            // Track this object type
            $redis->sadd("{$this->prefix()}:objects", $object);

            // Track this record under its object type
            $recordsKey = "{$this->prefix()}:records:{$object}";
            $redis->sadd($recordsKey, $id);
            $redis->expire($recordsKey, $ttl);
        } catch (Throwable $e) {
            $this->logFailure('register', $e);
        }
    }

    /**
     * Register a parent-child relationship for cascade invalidation.
     */
    public function registerRelationship(
        string $parentObject,
        string $parentId,
        string $childObject,
        string $childId,
    ): void {
        try {
            $ttl = $this->ttl();
            $redis = $this->redis();

            // Forward: parent → child
            $relationKey = "{$this->prefix()}:rel:{$parentObject}:{$parentId}";
            $redis->sadd($relationKey, "{$childObject}:{$childId}");
            $redis->expire($relationKey, $ttl);

            // Reverse: child → parent
            $reverseKey = "{$this->prefix()}:rrel:{$childObject}:{$childId}";
            $redis->sadd($reverseKey, "{$parentObject}:{$parentId}");
            $redis->expire($reverseKey, $ttl);
        } catch (Throwable $e) {
            $this->logFailure('registerRelationship', $e);
        }
    }

    /**
     * Get all cache keys associated with a record.
     *
     * @return string[]
     */
    public function getKeysForRecord(string $object, string $id): array
    {
        try {
            return $this->redis()->smembers($this->recordKey($object, $id)) ?: [];
        } catch (Throwable $e) {
            $this->logFailure('getKeysForRecord', $e);

            return [];
        }
    }

    /**
     * Get all parent record identifiers for a child (reverse relationship lookup).
     *
     * @return string[]
     */
    public function getParentRecords(string $childObject, string $childId): array
    {
        try {
            $reverseKey = "{$this->prefix()}:rrel:{$childObject}:{$childId}";

            return $this->redis()->smembers($reverseKey) ?: [];
        } catch (Throwable $e) {
            $this->logFailure('getParentRecords', $e);

            return [];
        }
    }

    /**
     * Get all related record identifiers for a parent.
     *
     * @return string[]
     */
    public function getRelatedRecords(string $object, string $id): array
    {
        try {
            $relationKey = "{$this->prefix()}:rel:{$object}:{$id}";

            return $this->redis()->smembers($relationKey) ?: [];
        } catch (Throwable $e) {
            $this->logFailure('getRelatedRecords', $e);

            return [];
        }
    }

    /**
     * Remove all registry entries for a record.
     */
    public function remove(string $object, string $id): void
    {
        try {
            $this->redis()->del($this->recordKey($object, $id));
            $this->redis()->del("{$this->prefix()}:rel:{$object}:{$id}");
            $this->redis()->del("{$this->prefix()}:rrel:{$object}:{$id}");
            $this->redis()->srem("{$this->prefix()}:records:{$object}", $id);
        } catch (Throwable $e) {
            $this->logFailure('remove', $e);
        }
    }

    /**
     * Get all tracked object types.
     *
     * @return string[]
     */
    public function getTrackedObjectTypes(): array
    {
        try {
            return $this->redis()->smembers("{$this->prefix()}:objects") ?: [];
        } catch (Throwable $e) {
            $this->logFailure('getTrackedObjectTypes', $e);

            return [];
        }
    }

    /**
     * Count tracked records for an object type.
     */
    public function countForObject(string $object): int
    {
        try {
            return (int) $this->redis()->scard("{$this->prefix()}:records:{$object}");
        } catch (Throwable $e) {
            $this->logFailure('countForObject', $e);

            return 0;
        }
    }

    /**
     * Flush all registry data using SCAN (non-blocking) instead of KEYS.
     */
    public function flush(): void
    {
        try {
            $pattern = "{$this->prefix()}:*";
            $cursor = null;

            do {
                [$cursor, $keys] = $this->redis()->scan($cursor, ['match' => $pattern, 'count' => 100]);

                if (! empty($keys)) {
                    $this->redis()->del(...$keys);
                }
            } while (! in_array($cursor, [0, '0', false], true));
        } catch (Throwable $e) {
            $this->logFailure('flush', $e);
        }
    }

    private function recordKey(string $object, string $id): string
    {
        return "{$this->prefix()}:{$object}:{$id}";
    }

    /**
     * Get the registry key prefix. Read on each call to avoid stale singleton values.
     */
    private function prefix(): string
    {
        return config('salesforce-cache.registry_prefix', 'sf_cache_registry');
    }

    /**
     * Get the registry TTL (2x cache TTL). Read on each call to avoid stale singleton values.
     */
    private function ttl(): int
    {
        return (int) config('salesforce-cache.ttl', 1800) * 2;
    }

    private function redis(): PhpRedisConnection
    {
        $store = config('salesforce-cache.store', 'redis');
        $connection = config("cache.stores.{$store}.connection", 'default');

        /** @var PhpRedisConnection $conn */
        $conn = Redis::connection($connection);

        return $conn;
    }

    private function logFailure(string $operation, Throwable $e): void
    {
        if (config('salesforce-cache.log_enabled', false)) {
            $channel = config('salesforce-cache.log_channel');
            $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();
            $logger->warning("[SalesforceCache] TagRegistry {$operation} failed", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
