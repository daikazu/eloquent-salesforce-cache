<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache\Support;

/**
 * Builds cache keys and the tags that go with them.
 *
 * A tagged cache entry can only be read or forgotten with the exact tags it was stored under.
 * The TagRegistry holds keys alone, so a query spanning several objects carries them in its
 * key: sf_cache:query:{md5}:Account,Contact. A single-object key has no suffix.
 */
final class CacheKey
{
    private const string PREFIX = 'sf_cache';

    /**
     * @param  string[]  $objects  Every object the query reads, root object first
     */
    public static function make(string $type, string $soql, array $objects = []): string
    {
        $key = self::PREFIX . ':' . $type . ':' . md5($soql);

        return count($objects) > 1 ? $key . ':' . implode(',', $objects) : $key;
    }

    /**
     * @param  string[]  $objects  Every object the query reads, root object first
     * @return string[]
     */
    public static function tags(array $objects): array
    {
        return ['salesforce', ...$objects];
    }

    /**
     * The tags an existing key was stored under.
     *
     * @param  string  $object  The object the key was found under, for single-object keys
     * @return string[]
     */
    public static function tagsForKey(string $cacheKey, string $object): array
    {
        $parts = explode(':', $cacheKey);

        return self::tags(isset($parts[3]) ? explode(',', $parts[3]) : [$object]);
    }
}
