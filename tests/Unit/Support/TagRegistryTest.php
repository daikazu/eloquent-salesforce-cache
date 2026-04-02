<?php

declare(strict_types=1);

use Daikazu\EloquentSalesforceCache\Support\TagRegistry;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    $this->redis = Mockery::mock(PhpRedisConnection::class);
    Redis::shouldReceive('connection')->andReturn($this->redis);
    $this->registry = new TagRegistry;
});

describe('register', function (): void {
    it('adds cache key to record set and tracks object and record', function (): void {
        $this->redis->shouldReceive('sadd')
            ->with('sf_cache_registry:Opportunity:001', 'sf_cache:query:abc123')
            ->once();
        $this->redis->shouldReceive('expire')
            ->with('sf_cache_registry:Opportunity:001', 3600)
            ->once();
        $this->redis->shouldReceive('sadd')
            ->with('sf_cache_registry:objects', 'Opportunity')
            ->once();
        $this->redis->shouldReceive('sadd')
            ->with('sf_cache_registry:records:Opportunity', '001')
            ->once();
        $this->redis->shouldReceive('expire')
            ->with('sf_cache_registry:records:Opportunity', 3600)
            ->once();

        $this->registry->register('Opportunity', '001', 'sf_cache:query:abc123');
    });

    it('uses 2x cache TTL for expiration', function (): void {
        config()->set('salesforce-cache.ttl', 900);

        $this->redis->shouldReceive('sadd')->times(3);
        $this->redis->shouldReceive('expire')
            ->with(Mockery::any(), 1800)
            ->twice();

        $this->registry->register('Opportunity', '001', 'key');
    });

    it('reads config lazily not at construction time', function (): void {
        config()->set('salesforce-cache.registry_prefix', 'custom_prefix');

        $this->redis->shouldReceive('sadd')
            ->with('custom_prefix:Opportunity:001', 'key')
            ->once();
        $this->redis->shouldReceive('sadd')
            ->with('custom_prefix:objects', 'Opportunity')
            ->once();
        $this->redis->shouldReceive('sadd')
            ->with('custom_prefix:records:Opportunity', '001')
            ->once();
        $this->redis->shouldReceive('expire')->twice();

        $this->registry->register('Opportunity', '001', 'key');
    });

    it('degrades gracefully on Redis failure', function (): void {
        $this->redis->shouldReceive('sadd')
            ->andThrow(new RuntimeException('Redis down'));

        $this->registry->register('Opportunity', '001', 'key');

        expect(true)->toBeTrue(); // no exception thrown
    });
});

describe('registerRelationship', function (): void {
    it('adds child record to parent relationship set and reverse mapping', function (): void {
        // Forward: parent → child
        $this->redis->shouldReceive('sadd')
            ->with('sf_cache_registry:rel:Opportunity:001', 'LineItem:002')
            ->once();
        $this->redis->shouldReceive('expire')
            ->with('sf_cache_registry:rel:Opportunity:001', 3600)
            ->once();

        // Reverse: child → parent
        $this->redis->shouldReceive('sadd')
            ->with('sf_cache_registry:rrel:LineItem:002', 'Opportunity:001')
            ->once();
        $this->redis->shouldReceive('expire')
            ->with('sf_cache_registry:rrel:LineItem:002', 3600)
            ->once();

        $this->registry->registerRelationship('Opportunity', '001', 'LineItem', '002');
    });
});

describe('getKeysForRecord', function (): void {
    it('returns cache keys from Redis set', function (): void {
        $this->redis->shouldReceive('smembers')
            ->with('sf_cache_registry:Opportunity:001')
            ->andReturn(['key1', 'key2']);

        expect($this->registry->getKeysForRecord('Opportunity', '001'))
            ->toBe(['key1', 'key2']);
    });

    it('returns empty array on Redis failure', function (): void {
        $this->redis->shouldReceive('smembers')
            ->andThrow(new RuntimeException('Redis down'));

        expect($this->registry->getKeysForRecord('Opportunity', '001'))
            ->toBe([]);
    });

    it('returns empty array when set is empty', function (): void {
        $this->redis->shouldReceive('smembers')
            ->andReturn([]);

        expect($this->registry->getKeysForRecord('Opportunity', '001'))
            ->toBe([]);
    });
});

describe('getRelatedRecords', function (): void {
    it('returns related record identifiers', function (): void {
        $this->redis->shouldReceive('smembers')
            ->with('sf_cache_registry:rel:Opportunity:001')
            ->andReturn(['LineItem:002', 'LineItem:003']);

        expect($this->registry->getRelatedRecords('Opportunity', '001'))
            ->toBe(['LineItem:002', 'LineItem:003']);
    });

    it('returns empty array on failure', function (): void {
        $this->redis->shouldReceive('smembers')
            ->andThrow(new RuntimeException('Redis down'));

        expect($this->registry->getRelatedRecords('Opportunity', '001'))
            ->toBe([]);
    });
});

describe('getParentRecords', function (): void {
    it('returns parent record identifiers', function (): void {
        $this->redis->shouldReceive('smembers')
            ->with('sf_cache_registry:rrel:LineItem:002')
            ->andReturn(['Opportunity:001', 'Opportunity:005']);

        expect($this->registry->getParentRecords('LineItem', '002'))
            ->toBe(['Opportunity:001', 'Opportunity:005']);
    });

    it('returns empty array when no parents exist', function (): void {
        $this->redis->shouldReceive('smembers')
            ->with('sf_cache_registry:rrel:LineItem:002')
            ->andReturn([]);

        expect($this->registry->getParentRecords('LineItem', '002'))
            ->toBe([]);
    });

    it('returns empty array on Redis failure', function (): void {
        $this->redis->shouldReceive('smembers')
            ->andThrow(new RuntimeException('Redis down'));

        expect($this->registry->getParentRecords('LineItem', '002'))
            ->toBe([]);
    });
});

describe('remove', function (): void {
    it('deletes record key, relationship key, reverse relationship key, and removes from object records set', function (): void {
        $this->redis->shouldReceive('del')
            ->with('sf_cache_registry:Opportunity:001')
            ->once();
        $this->redis->shouldReceive('del')
            ->with('sf_cache_registry:rel:Opportunity:001')
            ->once();
        $this->redis->shouldReceive('del')
            ->with('sf_cache_registry:rrel:Opportunity:001')
            ->once();
        $this->redis->shouldReceive('srem')
            ->with('sf_cache_registry:records:Opportunity', '001')
            ->once();

        $this->registry->remove('Opportunity', '001');
    });
});

describe('getTrackedObjectTypes', function (): void {
    it('returns object types from the objects set', function (): void {
        $this->redis->shouldReceive('smembers')
            ->with('sf_cache_registry:objects')
            ->andReturn(['Opportunity', 'Account']);

        expect($this->registry->getTrackedObjectTypes())
            ->toBe(['Opportunity', 'Account']);
    });
});

describe('countForObject', function (): void {
    it('returns count from scard', function (): void {
        $this->redis->shouldReceive('scard')
            ->with('sf_cache_registry:records:Opportunity')
            ->andReturn(42);

        expect($this->registry->countForObject('Opportunity'))
            ->toBe(42);
    });
});

describe('flush', function (): void {
    it('scans and deletes all registry keys', function (): void {
        $this->redis->shouldReceive('scan')
            ->with(null, ['match' => 'sf_cache_registry:*', 'count' => 100])
            ->andReturn([5, ['sf_cache_registry:Opportunity:001', 'sf_cache_registry:objects']]);

        $this->redis->shouldReceive('del')
            ->with('sf_cache_registry:Opportunity:001', 'sf_cache_registry:objects')
            ->once();

        $this->redis->shouldReceive('scan')
            ->with(5, ['match' => 'sf_cache_registry:*', 'count' => 100])
            ->andReturn([0, []]);

        $this->registry->flush();
    });

    it('handles string zero cursor from phpredis', function (): void {
        $this->redis->shouldReceive('scan')
            ->with(null, ['match' => 'sf_cache_registry:*', 'count' => 100])
            ->andReturn(['0', ['sf_cache_registry:key1']]);

        $this->redis->shouldReceive('del')
            ->with('sf_cache_registry:key1')
            ->once();

        $this->registry->flush();
    });
});
