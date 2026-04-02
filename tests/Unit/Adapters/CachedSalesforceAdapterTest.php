<?php

declare(strict_types=1);

use Daikazu\EloquentSalesforceCache\Adapters\CachedSalesforceAdapter;
use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;
use Daikazu\EloquentSalesforceCache\Support\ModelDiscovery;
use Daikazu\EloquentSalesforceCache\Support\TagRegistry;
use Daikazu\EloquentSalesforceObjects\Support\AuthenticationManager;
use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggedCache;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Testable subclass that stubs out parent Salesforce API calls
 * so we can test the caching layer in isolation.
 */
if (! class_exists('TestableCachedAdapter')) {
    class TestableCachedAdapter extends CachedSalesforceAdapter
    {
        /** @var array<string, array> */
        public array $parentQueryResults = [];

        /** @var string[] */
        public array $parentCallLog = [];

        public bool $parentQueryShouldThrow = false;

        public function setParentQueryResult(string $soql, array $result): void
        {
            $this->parentQueryResults[$soql] = $result;
        }

        /** Override parent::query to return canned results without SF network calls. */
        public function callParentQuery(string $soql): array
        {
            $this->parentCallLog[] = "query:{$soql}";

            if ($this->parentQueryShouldThrow) {
                throw new RuntimeException('Simulated Salesforce error');
            }

            return $this->parentQueryResults[$soql] ?? ['totalSize' => 0, 'records' => []];
        }

        /** Override parent::queryAll similarly. */
        public function callParentQueryAll(string $soql): array
        {
            $this->parentCallLog[] = "queryAll:{$soql}";

            return $this->parentQueryResults[$soql] ?? ['totalSize' => 0, 'records' => []];
        }

        /**
         * We cannot easily intercept parent::query inside cachedQuery's closure.
         * Instead, expose cachedQuery as public for direct testing.
         *
         * @param  callable(): array  $callback
         */
        public function testCachedQuery(string $type, string $soql, callable $callback): array
        {
            return $this->callCachedQuery($type, $soql, $callback);
        }

        /**
         * Call the private cachedQuery method via reflection.
         *
         * @param  callable(): array  $callback
         */
        private function callCachedQuery(string $type, string $soql, callable $callback): array
        {
            $reflection = new ReflectionMethod(CachedSalesforceAdapter::class, 'cachedQuery');
            $reflection->setAccessible(true);

            return $reflection->invoke($this, $type, $soql, $callback);
        }

        /**
         * Expose safeInvalidateObject for write-through invalidation tests.
         */
        public function testSafeInvalidateObject(string $object): void
        {
            $reflection = new ReflectionMethod(CachedSalesforceAdapter::class, 'safeInvalidateObject');
            $reflection->setAccessible(true);
            $reflection->invoke($this, $object);
        }

        /**
         * Expose buildCacheKey for key-format tests.
         */
        public function testBuildCacheKey(string $type, string $soql): string
        {
            $reflection = new ReflectionMethod(CachedSalesforceAdapter::class, 'buildCacheKey');
            $reflection->setAccessible(true);

            return $reflection->invoke($this, $type, $soql);
        }

        /**
         * Expose extractObjectName for SOQL parsing tests.
         */
        public function testExtractObjectName(string $soql): ?string
        {
            $reflection = new ReflectionMethod(CachedSalesforceAdapter::class, 'extractObjectName');
            $reflection->setAccessible(true);

            return $reflection->invoke($this, $soql);
        }

        /**
         * Expose safeCascadeInvalidation for cascade invalidation tests.
         */
        public function testSafeCascadeInvalidation(string $object, array $recordIds): void
        {
            $reflection = new ReflectionMethod(CachedSalesforceAdapter::class, 'safeCascadeInvalidation');
            $reflection->setAccessible(true);
            $reflection->invoke($this, $object, $recordIds);
        }
    }
}

/**
 * Build a TestableCachedAdapter with the TagRegistry mocked in the container.
 */
function makeAdapter(): TestableCachedAdapter
{
    $authManager = Mockery::mock(AuthenticationManager::class);
    $authManager->shouldReceive('ensureAuthenticated')->andReturn(null)->byDefault();

    $registry = Mockery::mock(TagRegistry::class);
    $registry->shouldReceive('register')->byDefault();
    app()->instance(TagRegistry::class, $registry);

    return new TestableCachedAdapter($authManager);
}

/**
 * Set up a tagged-cache mock and wire it into the Cache facade.
 *
 * @return array{tagged: TaggedCache, store: Repository}
 */
function mockTaggedCache(): array
{
    $tagged = Mockery::mock(TaggedCache::class);
    $tagged->shouldReceive('get')->andReturn(null)->byDefault();
    $tagged->shouldReceive('put')->byDefault();
    $tagged->shouldReceive('flush')->byDefault();

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->andReturn(true)->byDefault();
    $lock->shouldReceive('release')->byDefault();

    $innerStore = Mockery::mock(LockProvider::class);
    $innerStore->shouldReceive('lock')->andReturn($lock)->byDefault();

    $store = Mockery::mock(Repository::class);
    $store->shouldReceive('tags')->andReturn($tagged)->byDefault();
    $store->shouldReceive('getStore')->andReturn($innerStore)->byDefault();

    Cache::shouldReceive('store')->andReturn($store)->byDefault();

    return ['tagged' => $tagged, 'store' => $store];
}

// ---------------------------------------------------------------------------
// Cache key format
// ---------------------------------------------------------------------------

describe('cache key format', function (): void {
    it('builds the correct cache key for query type', function (): void {
        $adapter = makeAdapter();
        $soql = 'SELECT Id FROM Opportunity';

        $key = $adapter->testBuildCacheKey('query', $soql);

        expect($key)->toBe('sf_cache:query:' . md5($soql));
    });

    it('builds the correct cache key for queryAll type', function (): void {
        $adapter = makeAdapter();
        $soql = 'SELECT Id FROM Opportunity';

        $key = $adapter->testBuildCacheKey('queryAll', $soql);

        expect($key)->toBe('sf_cache:queryAll:' . md5($soql));
    });

    it('produces different keys for different SOQL', function (): void {
        $adapter = makeAdapter();

        $key1 = $adapter->testBuildCacheKey('query', 'SELECT Id FROM Opportunity');
        $key2 = $adapter->testBuildCacheKey('query', 'SELECT Id FROM Contact');

        expect($key1)->not->toBe($key2);
    });

    it('produces different keys for query vs queryAll on same SOQL', function (): void {
        $adapter = makeAdapter();
        $soql = 'SELECT Id FROM Opportunity';

        $key1 = $adapter->testBuildCacheKey('query', $soql);
        $key2 = $adapter->testBuildCacheKey('queryAll', $soql);

        expect($key1)->not->toBe($key2);
    });
});

// ---------------------------------------------------------------------------
// SOQL object extraction
// ---------------------------------------------------------------------------

describe('SOQL object extraction', function (): void {
    it('extracts standard object name from SOQL', function (): void {
        $adapter = makeAdapter();

        expect($adapter->testExtractObjectName('SELECT Id FROM Opportunity'))->toBe('Opportunity');
    });

    it('extracts custom object name from SOQL', function (): void {
        $adapter = makeAdapter();

        expect($adapter->testExtractObjectName('SELECT Id FROM My_Custom_Object__c'))->toBe('My_Custom_Object__c');
    });

    it('is case-insensitive on FROM keyword', function (): void {
        $adapter = makeAdapter();

        expect($adapter->testExtractObjectName('SELECT Id from Contact WHERE Name = \'test\''))->toBe('Contact');
    });

    it('returns null when no FROM clause present', function (): void {
        $adapter = makeAdapter();

        expect($adapter->testExtractObjectName('SHOW TABLES'))->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Cache hit — returns cached data without calling the callback
// ---------------------------------------------------------------------------

describe('query caching — cache hit', function (): void {
    it('returns cached result without invoking the callback', function (): void {
        ['tagged' => $tagged] = mockTaggedCache();

        $soql = 'SELECT Id FROM Opportunity';
        $cached = ['totalSize' => 1, 'records' => [['Id' => '001']]];
        $cacheKey = 'sf_cache:query:' . md5($soql);

        $tagged->shouldReceive('get')
            ->with($cacheKey)
            ->andReturn($cached);

        $adapter = makeAdapter();
        $callbackInvoked = false;

        $result = $adapter->testCachedQuery('query', $soql, function () use (&$callbackInvoked): array {
            $callbackInvoked = true;

            return ['totalSize' => 0, 'records' => []];
        });

        expect($result)->toBe($cached);
        expect($callbackInvoked)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// Cache miss — callback is invoked and result is stored
// ---------------------------------------------------------------------------

describe('query caching — cache miss', function (): void {
    it('calls the callback and stores result on cache miss', function (): void {
        ['tagged' => $tagged] = mockTaggedCache();

        $soql = 'SELECT Id FROM Opportunity';
        $sfResult = ['totalSize' => 1, 'records' => [['Id' => '001']]];
        $cacheKey = 'sf_cache:query:' . md5($soql);

        // All gets return null (miss + double-check after lock)
        $tagged->shouldReceive('get')
            ->with($cacheKey)
            ->andReturn(null);

        $tagged->shouldReceive('put')
            ->with($cacheKey, $sfResult, Mockery::any())
            ->once();

        $adapter = makeAdapter();
        $callbackInvoked = false;

        $result = $adapter->testCachedQuery('query', $soql, function () use ($sfResult, &$callbackInvoked): array {
            $callbackInvoked = true;

            return $sfResult;
        });

        expect($result)->toBe($sfResult);
        expect($callbackInvoked)->toBeTrue();
    });

    it('double-checks cache after acquiring lock and returns if populated', function (): void {
        ['tagged' => $tagged] = mockTaggedCache();

        $soql = 'SELECT Id FROM Opportunity';
        $cacheKey = 'sf_cache:query:' . md5($soql);
        $populated = ['totalSize' => 1, 'records' => [['Id' => '002']]];

        // First get = miss; second get (double-check) = populated by another request
        $tagged->shouldReceive('get')
            ->with($cacheKey)
            ->andReturn(null, $populated);

        // put should NOT be called since double-check found data
        $tagged->shouldNotReceive('put');

        $adapter = makeAdapter();
        $callbackInvoked = false;

        $result = $adapter->testCachedQuery('query', $soql, function () use (&$callbackInvoked): array {
            $callbackInvoked = true;

            return ['totalSize' => 0, 'records' => []];
        });

        expect($result)->toBe($populated);
        expect($callbackInvoked)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// Excluded objects — bypass cache entirely
// ---------------------------------------------------------------------------

describe('excluded objects', function (): void {
    it('bypasses cache for excluded objects without touching tagged cache', function (): void {
        ['tagged' => $tagged] = mockTaggedCache();

        config()->set('salesforce-cache.exclude_objects', ['Task']);

        $tagged->shouldNotReceive('get');
        $tagged->shouldNotReceive('put');

        $adapter = makeAdapter();
        $callbackResult = ['totalSize' => 1, 'records' => [['Id' => '003']]];
        $callbackInvoked = false;

        $result = $adapter->testCachedQuery('query', 'SELECT Id FROM Task', function () use ($callbackResult, &$callbackInvoked): array {
            $callbackInvoked = true;

            return $callbackResult;
        });

        expect($result)->toBe($callbackResult);
        expect($callbackInvoked)->toBeTrue();
    });

    it('does not exclude non-excluded objects', function (): void {
        ['tagged' => $tagged] = mockTaggedCache();

        config()->set('salesforce-cache.exclude_objects', ['Task']);

        $soql = 'SELECT Id FROM Opportunity';
        $cacheKey = 'sf_cache:query:' . md5($soql);
        $sfResult = ['totalSize' => 1, 'records' => []];

        $tagged->shouldReceive('get')->with($cacheKey)->andReturn(null);
        $tagged->shouldReceive('put')->once();

        $adapter = makeAdapter();
        $adapter->testCachedQuery('query', $soql, fn () => $sfResult);
    });
});

// ---------------------------------------------------------------------------
// Disabled caching — bypass entirely
// ---------------------------------------------------------------------------

describe('disabled caching', function (): void {
    it('bypasses cache when adapter.enabled is false', function (): void {
        ['tagged' => $tagged] = mockTaggedCache();

        config()->set('salesforce-cache.enabled', false);

        $tagged->shouldNotReceive('get');
        $tagged->shouldNotReceive('put');

        $adapter = makeAdapter();
        $callbackResult = ['totalSize' => 99, 'records' => []];
        $callbackInvoked = false;

        $result = $adapter->testCachedQuery('query', 'SELECT Id FROM Opportunity', function () use ($callbackResult, &$callbackInvoked): array {
            $callbackInvoked = true;

            return $callbackResult;
        });

        expect($result)->toBe($callbackResult);
        expect($callbackInvoked)->toBeTrue();
    });

    it('still bypasses when disabled even for non-excluded objects', function (): void {
        ['tagged' => $tagged] = mockTaggedCache();

        config()->set('salesforce-cache.enabled', false);
        config()->set('salesforce-cache.exclude_objects', []);

        $tagged->shouldNotReceive('get');

        $adapter = makeAdapter();
        $adapter->testCachedQuery('query', 'SELECT Id FROM Contact', fn () => []);
    });
});

// ---------------------------------------------------------------------------
// Write-through invalidation — safeInvalidateObject flushes tagged cache
// ---------------------------------------------------------------------------

describe('write-through invalidation', function (): void {
    it('flushes the tagged cache for the object after create', function (): void {
        ['tagged' => $tagged] = mockTaggedCache();

        $tagged->shouldReceive('flush')->once();

        $adapter = makeAdapter();
        $adapter->testSafeInvalidateObject('Opportunity');
    });

    it('flushes with the correct tags', function (): void {
        $tagged = Mockery::mock(TaggedCache::class);
        $tagged->shouldReceive('flush')->once();
        $tagged->shouldReceive('get')->andReturn(null)->byDefault();
        $tagged->shouldReceive('put')->byDefault();

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->andReturn(true)->byDefault();
        $lock->shouldReceive('release')->byDefault();

        $innerStore = Mockery::mock(LockProvider::class);
        $innerStore->shouldReceive('lock')->andReturn($lock)->byDefault();

        $store = Mockery::mock(Repository::class);
        $store->shouldReceive('tags')
            ->with(['salesforce', 'Contact'])
            ->andReturn($tagged)
            ->once();
        $store->shouldReceive('getStore')->andReturn($innerStore)->byDefault();

        Cache::shouldReceive('store')->andReturn($store)->byDefault();

        $adapter = makeAdapter();
        $adapter->testSafeInvalidateObject('Contact');
    });

    it('does not flush when caching is disabled', function (): void {
        ['tagged' => $tagged] = mockTaggedCache();

        config()->set('salesforce-cache.enabled', false);

        $tagged->shouldNotReceive('flush');

        $adapter = makeAdapter();
        $adapter->testSafeInvalidateObject('Opportunity');
    });
});

// ---------------------------------------------------------------------------
// Graceful degradation — cache failures do not throw
// ---------------------------------------------------------------------------

describe('graceful degradation', function (): void {
    it('falls through to callback when tagged cache get throws', function (): void {
        $tagged = Mockery::mock(TaggedCache::class);
        $tagged->shouldReceive('get')->andThrow(new RuntimeException('Redis connection refused'));
        $tagged->shouldReceive('put')->byDefault();

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->andReturn(true)->byDefault();
        $lock->shouldReceive('release')->byDefault();

        $innerStore = Mockery::mock(LockProvider::class);
        $innerStore->shouldReceive('lock')->andReturn($lock)->byDefault();

        $store = Mockery::mock(Repository::class);
        $store->shouldReceive('tags')->andReturn($tagged)->byDefault();
        $store->shouldReceive('getStore')->andReturn($innerStore)->byDefault();

        Cache::shouldReceive('store')->andReturn($store)->byDefault();

        $adapter = makeAdapter();
        $callbackResult = ['totalSize' => 1, 'records' => [['Id' => '004']]];

        $result = $adapter->testCachedQuery('query', 'SELECT Id FROM Opportunity', fn () => $callbackResult);

        expect($result)->toBe($callbackResult);
    });

    it('does not throw when lock acquisition fails', function (): void {
        $tagged = Mockery::mock(TaggedCache::class);
        $tagged->shouldReceive('get')->andReturn(null);
        $tagged->shouldReceive('put')->byDefault();

        $innerStore = Mockery::mock(LockProvider::class);
        $innerStore->shouldReceive('lock')->andThrow(new RuntimeException('Redis unavailable'));

        $store = Mockery::mock(Repository::class);
        $store->shouldReceive('tags')->andReturn($tagged)->byDefault();
        $store->shouldReceive('getStore')->andReturn($innerStore)->byDefault();

        Cache::shouldReceive('store')->andReturn($store)->byDefault();

        $adapter = makeAdapter();
        $callbackResult = ['totalSize' => 0, 'records' => []];

        // Should not throw — falls through to callback
        $result = $adapter->testCachedQuery('query', 'SELECT Id FROM Opportunity', fn () => $callbackResult);

        expect($result)->toBe($callbackResult);
    });

    it('does not throw when cache invalidation fails', function (): void {
        $tagged = Mockery::mock(TaggedCache::class);
        $tagged->shouldReceive('flush')->andThrow(new RuntimeException('Redis gone'));

        $store = Mockery::mock(Repository::class);
        $store->shouldReceive('tags')->andReturn($tagged)->byDefault();

        Cache::shouldReceive('store')->andReturn($store)->byDefault();

        $adapter = makeAdapter();

        // Should not throw
        expect(fn () => $adapter->testSafeInvalidateObject('Opportunity'))->not->toThrow(Exception::class);
    });
});

// ---------------------------------------------------------------------------
// TagRegistry integration — records are registered on cache write
// ---------------------------------------------------------------------------

describe('TagRegistry integration', function (): void {
    it('registers record IDs in the TagRegistry on cache write', function (): void {
        ['tagged' => $tagged] = mockTaggedCache();

        $soql = 'SELECT Id FROM Opportunity';
        $cacheKey = 'sf_cache:query:' . md5($soql);
        $sfResult = [
            'totalSize' => 2,
            'records'   => [
                ['Id' => '001ABC'],
                ['Id' => '002DEF'],
            ],
        ];

        $tagged->shouldReceive('get')->with($cacheKey)->andReturn(null);
        $tagged->shouldReceive('put')->with($cacheKey, $sfResult, Mockery::any())->once();

        $registry = Mockery::mock(TagRegistry::class);
        $registry->shouldReceive('register')->with('Opportunity', '001ABC', $cacheKey)->once();
        $registry->shouldReceive('register')->with('Opportunity', '002DEF', $cacheKey)->once();
        app()->instance(TagRegistry::class, $registry);

        $authManager = Mockery::mock(AuthenticationManager::class);
        $authManager->shouldReceive('ensureAuthenticated')->byDefault();

        $adapter = new TestableCachedAdapter($authManager);
        $adapter->testCachedQuery('query', $soql, fn () => $sfResult);
    });

});

// ---------------------------------------------------------------------------
// Auto-cascade invalidation — parent records are invalidated on mutation
// ---------------------------------------------------------------------------

describe('auto-cascade invalidation', function (): void {
    it('cascades invalidation to parent records on mutation', function (): void {
        mockTaggedCache();

        $adapter = makeAdapter();

        $registry = Mockery::mock(TagRegistry::class);
        $registry->shouldReceive('register')->byDefault();
        $registry->shouldReceive('getParentRecords')
            ->with('OpportunityLineItem', '00k123')
            ->andReturn(['Opportunity:001']);
        app()->instance(TagRegistry::class, $registry);

        $invalidator = Mockery::mock(CacheInvalidator::class);
        $invalidator->shouldReceive('invalidateRecord')
            ->with('Opportunity', '001')
            ->once();
        app()->instance(CacheInvalidator::class, $invalidator);

        $discovery = Mockery::mock(ModelDiscovery::class);
        $discovery->shouldReceive('getCascadeTargets')
            ->with('OpportunityLineItem')
            ->andReturn(['Opportunity']);
        app()->instance(ModelDiscovery::class, $discovery);

        $adapter->testSafeCascadeInvalidation('OpportunityLineItem', ['00k123']);
    });

    it('does nothing when no cascade targets configured', function (): void {
        mockTaggedCache();

        $discovery = Mockery::mock(ModelDiscovery::class);
        $discovery->shouldReceive('getCascadeTargets')
            ->with('Opportunity')
            ->andReturn([]);
        app()->instance(ModelDiscovery::class, $discovery);

        $invalidator = Mockery::mock(CacheInvalidator::class);
        $invalidator->shouldNotReceive('invalidateRecord');
        app()->instance(CacheInvalidator::class, $invalidator);

        $adapter = makeAdapter();
        $adapter->testSafeCascadeInvalidation('Opportunity', ['001']);
    });

    it('does nothing when reverse registry has no parent records', function (): void {
        mockTaggedCache();

        $registry = Mockery::mock(TagRegistry::class);
        $registry->shouldReceive('register')->byDefault();
        $registry->shouldReceive('getParentRecords')
            ->with('OpportunityLineItem', '00k123')
            ->andReturn([]);
        app()->instance(TagRegistry::class, $registry);

        $discovery = Mockery::mock(ModelDiscovery::class);
        $discovery->shouldReceive('getCascadeTargets')
            ->with('OpportunityLineItem')
            ->andReturn(['Opportunity']);
        app()->instance(ModelDiscovery::class, $discovery);

        $invalidator = Mockery::mock(CacheInvalidator::class);
        $invalidator->shouldNotReceive('invalidateRecord');
        app()->instance(CacheInvalidator::class, $invalidator);

        $adapter = makeAdapter();
        $adapter->testSafeCascadeInvalidation('OpportunityLineItem', ['00k123']);
    });

    it('cascades to multiple parent types', function (): void {
        mockTaggedCache();

        $adapter = makeAdapter();

        $registry = Mockery::mock(TagRegistry::class);
        $registry->shouldReceive('register')->byDefault();
        $registry->shouldReceive('getParentRecords')
            ->with('Payment', 'pay001')
            ->andReturn(['Opportunity:001', 'Account:002']);
        app()->instance(TagRegistry::class, $registry);

        $invalidator = Mockery::mock(CacheInvalidator::class);
        $invalidator->shouldReceive('invalidateRecord')
            ->with('Opportunity', '001')
            ->once();
        $invalidator->shouldReceive('invalidateRecord')
            ->with('Account', '002')
            ->once();
        app()->instance(CacheInvalidator::class, $invalidator);

        $discovery = Mockery::mock(ModelDiscovery::class);
        $discovery->shouldReceive('getCascadeTargets')
            ->with('Payment')
            ->andReturn(['Opportunity', 'Account']);
        app()->instance(ModelDiscovery::class, $discovery);

        $adapter->testSafeCascadeInvalidation('Payment', ['pay001']);
    });

    it('degrades gracefully when cascade fails', function (): void {
        mockTaggedCache();

        $discovery = Mockery::mock(ModelDiscovery::class);
        $discovery->shouldReceive('getCascadeTargets')
            ->andThrow(new RuntimeException('Discovery failed'));
        app()->instance(ModelDiscovery::class, $discovery);

        $adapter = makeAdapter();

        expect(fn () => $adapter->testSafeCascadeInvalidation('OpportunityLineItem', ['00k123']))
            ->not->toThrow(Exception::class);
    });
});
