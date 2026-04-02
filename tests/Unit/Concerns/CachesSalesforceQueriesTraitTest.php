<?php

declare(strict_types=1);

use Daikazu\EloquentSalesforceCache\Concerns\CachesSalesforceQueries;
use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;
use Illuminate\Database\Eloquent\Model;

// Fake model for testing the trait
if (! class_exists('FakeSalesforceModel')) {
    class FakeSalesforceModel extends Model
    {
        use CachesSalesforceQueries;

        protected $table = 'FakeObject';

        protected $primaryKey = 'Id';

        protected $keyType = 'string';

        public $incrementing = false;
    }
}

if (! class_exists('CustomCacheModel')) {
    class CustomCacheModel extends Model
    {
        use CachesSalesforceQueries;

        protected $table = 'CustomObject';

        protected $primaryKey = 'Id';

        protected $keyType = 'string';

        public $incrementing = false;

        protected bool $cacheable = false;

        protected int $cacheTtl = 600;

        protected array $trackedRelationships = ['lineItems', 'payments'];
    }
}

if (! class_exists('InvalidatesObjectsModel')) {
    class InvalidatesObjectsModel extends Model
    {
        use CachesSalesforceQueries;

        protected $table = 'Contact';

        protected $primaryKey = 'Id';

        protected $keyType = 'string';

        public $incrementing = false;

        protected array $invalidatesObjects = ['Opportunity'];
    }
}

if (! class_exists('InvalidatesMultipleObjectsModel')) {
    class InvalidatesMultipleObjectsModel extends Model
    {
        use CachesSalesforceQueries;

        protected $table = 'Account';

        protected $primaryKey = 'Id';

        protected $keyType = 'string';

        public $incrementing = false;

        protected array $invalidatesObjects = ['Opportunity', 'Contact'];
    }
}

describe('isCacheable', function (): void {
    it('defaults to true', function (): void {
        $model = new FakeSalesforceModel;

        expect($model->isCacheable())->toBeTrue();
    });

    it('respects property override', function (): void {
        $model = new CustomCacheModel;

        expect($model->isCacheable())->toBeFalse();
    });
});

describe('getCacheTtl', function (): void {
    it('defaults to 1800', function (): void {
        $model = new FakeSalesforceModel;

        expect($model->getCacheTtl())->toBe(1800);
    });

    it('respects property override', function (): void {
        $model = new CustomCacheModel;

        expect($model->getCacheTtl())->toBe(600);
    });
});

describe('getTrackedRelationships', function (): void {
    it('defaults to empty array', function (): void {
        $model = new FakeSalesforceModel;

        expect($model->getTrackedRelationships())->toBe([]);
    });

    it('respects property override', function (): void {
        $model = new CustomCacheModel;

        expect($model->getTrackedRelationships())->toBe(['lineItems', 'payments']);
    });
});

describe('getInvalidatesObjects', function (): void {
    it('returns empty array when property is not defined', function (): void {
        $model = new FakeSalesforceModel;

        expect($model->getInvalidatesObjects())->toBe([]);
    });

    it('returns configured object names when property is defined', function (): void {
        $model = new InvalidatesObjectsModel;

        expect($model->getInvalidatesObjects())->toBe(['Opportunity']);
    });

    it('supports multiple cascade targets', function (): void {
        $model = new InvalidatesMultipleObjectsModel;

        expect($model->getInvalidatesObjects())->toBe(['Opportunity', 'Contact']);
    });
});

describe('invalidateCacheFor', function (): void {
    it('calls invalidator with object name and id', function (): void {
        $invalidator = Mockery::mock(CacheInvalidator::class);
        $invalidator->shouldReceive('invalidateRecord')
            ->with('FakeObject', '001')
            ->once();
        app()->instance(CacheInvalidator::class, $invalidator);

        FakeSalesforceModel::invalidateCacheFor('001');
    });
});

describe('invalidateCacheForMany', function (): void {
    it('calls invalidator for each id', function (): void {
        $invalidator = Mockery::mock(CacheInvalidator::class);
        $invalidator->shouldReceive('invalidateRecord')
            ->with('FakeObject', '001')
            ->once();
        $invalidator->shouldReceive('invalidateRecord')
            ->with('FakeObject', '002')
            ->once();
        app()->instance(CacheInvalidator::class, $invalidator);

        FakeSalesforceModel::invalidateCacheForMany(['001', '002']);
    });
});

describe('flushAllCache', function (): void {
    it('calls invalidateObject with table name', function (): void {
        $invalidator = Mockery::mock(CacheInvalidator::class);
        $invalidator->shouldReceive('invalidateObject')
            ->with('FakeObject')
            ->once();
        app()->instance(CacheInvalidator::class, $invalidator);

        FakeSalesforceModel::flushAllCache();
    });
});

describe('invalidateCache (instance)', function (): void {
    it('invalidates using model key', function (): void {
        $invalidator = Mockery::mock(CacheInvalidator::class);
        $invalidator->shouldReceive('invalidateRecord')
            ->with('FakeObject', '001')
            ->once();
        app()->instance(CacheInvalidator::class, $invalidator);

        $model = new FakeSalesforceModel;
        $model->Id = '001';
        $model->invalidateCache();
    });
});

describe('invalidateCacheWithRelationships (instance)', function (): void {
    it('calls invalidateRecordWithRelationships', function (): void {
        $invalidator = Mockery::mock(CacheInvalidator::class);
        $invalidator->shouldReceive('invalidateRecordWithRelationships')
            ->with('FakeObject', '001')
            ->once();
        app()->instance(CacheInvalidator::class, $invalidator);

        $model = new FakeSalesforceModel;
        $model->Id = '001';
        $model->invalidateCacheWithRelationships();
    });
});
