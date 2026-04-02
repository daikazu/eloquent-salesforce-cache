<?php

declare(strict_types=1);

use Daikazu\EloquentSalesforceCache\Enums\InvalidationScope;
use Daikazu\EloquentSalesforceCache\Events\CacheInvalidated;
use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;
use Daikazu\EloquentSalesforceCache\Support\TagRegistry;
use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggedCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake([CacheInvalidated::class]);

    $this->registry = Mockery::mock(TagRegistry::class);
    $this->invalidator = new CacheInvalidator($this->registry);

    $this->taggedCache = Mockery::mock(TaggedCache::class);
    $this->cacheRepo = Mockery::mock(Repository::class);
    $this->cacheRepo->shouldReceive('tags')->andReturn($this->taggedCache)->byDefault();
    Cache::shouldReceive('store')->andReturn($this->cacheRepo)->byDefault();
});

describe('invalidateRecord', function (): void {
    it('forgets cache keys and dispatches event with enum scope', function (): void {
        $this->registry->shouldReceive('getKeysForRecord')
            ->with('Opportunity', '001')
            ->andReturn(['sf_cache:query:abc']);
        $this->taggedCache->shouldReceive('forget')->with('sf_cache:query:abc')->once();
        $this->registry->shouldReceive('remove')->with('Opportunity', '001')->once();

        $this->invalidator->invalidateRecord('Opportunity', '001');

        Event::assertDispatched(CacheInvalidated::class, function (CacheInvalidated $event): bool {
            return $event->object === 'Opportunity'
                && $event->ids === ['001']
                && $event->scope === InvalidationScope::Record
                && $event->relatedRecords === [];
        });
    });

    it('does not dispatch event on failure', function (): void {
        $this->registry->shouldReceive('getKeysForRecord')
            ->andThrow(new RuntimeException('Redis down'));

        config()->set('salesforce-cache.log_enabled', false);

        $this->invalidator->invalidateRecord('Opportunity', '001');

        Event::assertNotDispatched(CacheInvalidated::class);
    });

    it('tries alternate __c name when primary has no keys', function (): void {
        $this->registry->shouldReceive('getKeysForRecord')
            ->with('Opportunity', '001')
            ->andReturn([]);
        $this->registry->shouldReceive('getKeysForRecord')
            ->with('Opportunity__c', '001')
            ->andReturn(['sf_cache:query:xyz']);
        $this->taggedCache->shouldReceive('forget')->with('sf_cache:query:xyz')->once();
        $this->registry->shouldReceive('remove')->with('Opportunity__c', '001')->once();

        $this->invalidator->invalidateRecord('Opportunity', '001');

        Event::assertDispatched(CacheInvalidated::class);
    });

    it('strips __c suffix when checking alternate', function (): void {
        $this->registry->shouldReceive('getKeysForRecord')
            ->with('Custom__c', '001')
            ->andReturn([]);
        $this->registry->shouldReceive('getKeysForRecord')
            ->with('Custom', '001')
            ->andReturn(['key1']);
        $this->taggedCache->shouldReceive('forget')->with('key1')->once();
        $this->registry->shouldReceive('remove')->with('Custom', '001')->once();

        $this->invalidator->invalidateRecord('Custom__c', '001');

        Event::assertDispatched(CacheInvalidated::class);
    });
});

describe('invalidateRecordWithRelationships', function (): void {
    it('cascades to related records and populates relatedRecords in event', function (): void {
        $this->registry->shouldReceive('getRelatedRecords')
            ->with('Opportunity', '001')
            ->andReturn(['LineItem:002', 'LineItem:003']);

        // Primary record
        $this->registry->shouldReceive('getKeysForRecord')
            ->with('Opportunity', '001')
            ->andReturn(['key1']);
        $this->taggedCache->shouldReceive('forget')->with('key1')->once();
        $this->registry->shouldReceive('remove')->with('Opportunity', '001')->once();

        // Related records
        $this->registry->shouldReceive('getKeysForRecord')
            ->with('LineItem', '002')
            ->andReturn(['key2']);
        $this->registry->shouldReceive('getKeysForRecord')
            ->with('LineItem', '003')
            ->andReturn(['key3']);
        $this->taggedCache->shouldReceive('forget')->with('key2')->once();
        $this->taggedCache->shouldReceive('forget')->with('key3')->once();
        $this->registry->shouldReceive('remove')->with('LineItem', '002')->once();
        $this->registry->shouldReceive('remove')->with('LineItem', '003')->once();

        $this->invalidator->invalidateRecordWithRelationships('Opportunity', '001');

        Event::assertDispatched(CacheInvalidated::class, function (CacheInvalidated $event): bool {
            return $event->object === 'Opportunity'
                && $event->ids === ['001']
                && $event->scope === InvalidationScope::Record
                && $event->relatedRecords === [
                    ['object' => 'LineItem', 'id' => '002'],
                    ['object' => 'LineItem', 'id' => '003'],
                ];
        });
    });

    it('does not dispatch event on failure', function (): void {
        $this->registry->shouldReceive('getRelatedRecords')
            ->andThrow(new RuntimeException('Redis down'));

        config()->set('salesforce-cache.log_enabled', false);

        $this->invalidator->invalidateRecordWithRelationships('Opportunity', '001');

        Event::assertNotDispatched(CacheInvalidated::class);
    });
});

describe('invalidateObject', function (): void {
    it('flushes object tag and dispatches event', function (): void {
        $this->registry->shouldReceive('getTrackedObjectTypes')->andReturn(['Opportunity']);
        $this->taggedCache->shouldReceive('flush')->once();

        $this->invalidator->invalidateObject('Opportunity');

        Event::assertDispatched(CacheInvalidated::class, function (CacheInvalidated $event): bool {
            return $event->object === 'Opportunity'
                && $event->ids === []
                && $event->scope === InvalidationScope::Object;
        });
    });

    it('resolves __c alternate for object name', function (): void {
        $this->registry->shouldReceive('getTrackedObjectTypes')->andReturn(['Opportunity']);
        $this->taggedCache->shouldReceive('flush')->once();

        $this->invalidator->invalidateObject('Opportunity__c');

        Event::assertDispatched(CacheInvalidated::class, function (CacheInvalidated $event): bool {
            return $event->object === 'Opportunity';
        });
    });
});

describe('invalidateAll', function (): void {
    it('flushes salesforce tag and registry, dispatches event', function (): void {
        $this->taggedCache->shouldReceive('flush')->once();
        $this->registry->shouldReceive('flush')->once();

        $this->invalidator->invalidateAll();

        Event::assertDispatched(CacheInvalidated::class, function (CacheInvalidated $event): bool {
            return $event->object === '*'
                && $event->ids === []
                && $event->scope === InvalidationScope::All;
        });
    });
});

describe('invalidateMany', function (): void {
    it('groups records by object and dispatches one event per object type', function (): void {
        // Two Opportunity + one Account = 2 events
        $this->registry->shouldReceive('getKeysForRecord')
            ->with('Opportunity', '001')
            ->andReturn(['k1']);
        $this->registry->shouldReceive('getKeysForRecord')
            ->with('Opportunity', '002')
            ->andReturn(['k2']);
        $this->registry->shouldReceive('getKeysForRecord')
            ->with('Account', '003')
            ->andReturn(['k3']);

        $this->taggedCache->shouldReceive('forget')->times(3);
        $this->registry->shouldReceive('remove')->times(3);

        $this->invalidator->invalidateMany([
            ['object' => 'Opportunity', 'id' => '001'],
            ['object' => 'Opportunity', 'id' => '002'],
            ['object' => 'Account', 'id' => '003'],
        ]);

        Event::assertDispatched(CacheInvalidated::class, function (CacheInvalidated $event): bool {
            return $event->object === 'Opportunity' && $event->ids === ['001', '002'];
        });

        Event::assertDispatched(CacheInvalidated::class, function (CacheInvalidated $event): bool {
            return $event->object === 'Account' && $event->ids === ['003'];
        });

        Event::assertDispatchedTimes(CacheInvalidated::class, 2);
    });
});
