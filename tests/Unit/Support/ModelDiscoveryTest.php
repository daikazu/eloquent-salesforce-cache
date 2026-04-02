<?php

declare(strict_types=1);

use Daikazu\EloquentSalesforceCache\Concerns\CachesSalesforceQueries;
use Daikazu\EloquentSalesforceCache\Support\ModelDiscovery;
use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use Illuminate\Support\Facades\Cache;

if (! class_exists('TestLineItemModel')) {
    class TestLineItemModel extends SalesforceModel
    {
        use CachesSalesforceQueries;

        protected $table = 'OpportunityLineItem';

        protected array $invalidatesObjects = ['Opportunity'];
    }
}

if (! class_exists('TestPaymentModel')) {
    class TestPaymentModel extends SalesforceModel
    {
        use CachesSalesforceQueries;

        protected $table = 'Payment';

        protected array $invalidatesObjects = ['Opportunity', 'Account'];
    }
}

if (! class_exists('TestOpportunityModel')) {
    class TestOpportunityModel extends SalesforceModel
    {
        use CachesSalesforceQueries;

        protected $table = 'Opportunity';
    }
}

beforeEach(function (): void {
    Cache::flush();
    $this->discovery = app(ModelDiscovery::class);
});

describe('getCascadeTargets', function (): void {
    it('returns cascade targets for a known object', function (): void {
        expect($this->discovery->getCascadeTargets('OpportunityLineItem'))
            ->toBe(['Opportunity']);
    });

    it('returns multiple targets when configured', function (): void {
        expect($this->discovery->getCascadeTargets('Payment'))
            ->toBe(['Opportunity', 'Account']);
    });

    it('returns empty array for objects without cascade config', function (): void {
        expect($this->discovery->getCascadeTargets('Opportunity'))
            ->toBe([]);
    });

    it('returns empty array for unknown objects', function (): void {
        expect($this->discovery->getCascadeTargets('NonExistentObject'))
            ->toBe([]);
    });
});

describe('caching', function (): void {
    it('caches discovery results', function (): void {
        $this->discovery->getCascadeTargets('OpportunityLineItem');

        expect(Cache::store(config('salesforce-cache.store'))->get('sf_cache:model_discovery'))
            ->toBeArray()
            ->toHaveKey('OpportunityLineItem');
    });

    it('uses cached results on subsequent calls', function (): void {
        Cache::store(config('salesforce-cache.store'))->put('sf_cache:model_discovery', [
            'FakeObject' => ['FakeParent'],
        ]);

        $fresh = new ModelDiscovery;
        expect($fresh->getCascadeTargets('FakeObject'))->toBe(['FakeParent']);
    });
});
