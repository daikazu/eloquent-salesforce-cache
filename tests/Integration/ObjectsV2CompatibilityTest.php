<?php

declare(strict_types=1);

use Daikazu\EloquentSalesforceCache\Adapters\CachedSalesforceAdapter;
use Daikazu\EloquentSalesforceCache\EloquentSalesforceCacheServiceProvider;
use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;
use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\EloquentSalesforceObjectsServiceProvider;
use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use Daikazu\EloquentSalesforceObjects\Support\AuthenticationManager;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Redis;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;
use Orchestra\Testbench\TestCase;

/*
 * Runs the real daikazu/eloquent-salesforce-objects query builder and a real Redis store.
 * Only the Forrest HTTP client is faked, so these fail if the v2 SOQL the builder sends
 * and the cache's tagging and invalidation stop agreeing.
 */

class CompatAccount extends SalesforceModel
{
    protected $table = 'Account';

    protected ?array $defaultColumns = ['Name'];

    public function contacts(): HasMany
    {
        return $this->hasMany(CompatContact::class, 'AccountId');
    }
}

class CompatContact extends SalesforceModel
{
    protected $table = 'Contact';

    protected ?array $defaultColumns = ['LastName', 'AccountId'];
}

abstract class ObjectsV2TestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // Same order as package discovery: "cache" registers before "objects".
        return [
            EloquentSalesforceCacheServiceProvider::class,
            EloquentSalesforceObjectsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.sf_test', ['driver' => 'redis', 'connection' => 'sf_test']);
        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.sf_test', [
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'port'     => env('REDIS_PORT', 6379),
            'database' => 15,
        ]);
        $app['config']->set('cache.prefix', 'sf_compat_test');
        $app['config']->set('salesforce-cache.store', 'sf_test');
        $app['config']->set('salesforce-cache.registry_prefix', 'sf_compat_test_registry');
    }
}

uses(ObjectsV2TestCase::class);

beforeEach(function (): void {
    try {
        Redis::connection('sf_test')->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis is not available.');
    }

    $auth = Mockery::mock(AuthenticationManager::class);
    $auth->shouldReceive('ensureAuthenticated');
    app()->instance(AuthenticationManager::class, $auth);

    app(CacheInvalidator::class)->invalidateAll();

    $this->soql = [];
    $this->rows = fn (string $name = 'Acme'): array => [
        'totalSize' => 1,
        'done'      => true,
        'records'   => [[
            'attributes' => ['type' => 'Account'],
            'Id'         => '001A',
            'Name'       => $name,
            'Contacts'   => [
                'totalSize' => 1,
                'done'      => true,
                'records'   => [[
                    'attributes' => ['type' => 'Contact'],
                    'Id'         => '003A',
                    'LastName'   => 'Smith',
                    'AccountId'  => '001A',
                ]],
            ],
        ]],
    ];

    $this->forrest = Mockery::mock();
    Forrest::swap($this->forrest);

    $this->forrest->shouldReceive('describe')->andReturnUsing(fn (?string $object = null): array => match ($object) {
        'Account' => [
            'fields'             => [['name' => 'Id'], ['name' => 'Name']],
            'childRelationships' => [
                ['childSObject' => 'Contact', 'field' => 'AccountId', 'relationshipName' => 'Contacts'],
            ],
        ],
        default => ['fields' => [['name' => 'Id'], ['name' => 'LastName'], ['name' => 'AccountId']], 'childRelationships' => []],
    });

    $this->forrest->shouldReceive('query')->andReturnUsing(function (string $soql): array {
        $this->soql[] = $soql;

        return ($this->rows)();
    });
});

afterEach(function (): void {
    app(CacheInvalidator::class)->invalidateAll();
});

it('binds the cached adapter for both the concrete class and the v2 interface singleton', function (): void {
    expect(app(SalesforceAdapter::class))->toBeInstanceOf(CachedSalesforceAdapter::class)
        ->and(app(AdapterInterface::class))->toBe(app(SalesforceAdapter::class));
});

it('caches a subquery eager load and hydrates children on a hit', function (): void {
    CompatAccount::with('contacts')->get();
    $accounts = CompatAccount::with('contacts')->get();

    expect($this->soql)->toHaveCount(1)
        ->and(strtolower($this->soql[0]))->toContain('from contacts')
        ->and($accounts->first()->contacts->first()->LastName)->toBe('Smith');
});

it('refetches a subquery eager load after invalidation', function (Closure $invalidate): void {
    CompatAccount::with('contacts')->get();

    $invalidate(app(CacheInvalidator::class));

    CompatAccount::with('contacts')->get();

    expect($this->soql)->toHaveCount(2);
})->with([
    'parent object' => [fn (CacheInvalidator $i) => $i->invalidateObject('Account')],
    'parent record' => [fn (CacheInvalidator $i) => $i->invalidateRecord('Account', '001A')],
    'child object'  => [fn (CacheInvalidator $i) => $i->invalidateObject('Contact')],
    'child record'  => [fn (CacheInvalidator $i) => $i->invalidateRecord('Contact', '003A')],
]);

it('refetches a subquery eager load after a child record is saved through the adapter', function (): void {
    $this->forrest->shouldReceive('sobjects')->andReturn(null);

    CompatAccount::with('contacts')->get();

    app(SalesforceAdapter::class)->update('Contact', '003A', ['LastName' => 'Jones']);

    CompatAccount::with('contacts')->get();

    expect($this->soql)->toHaveCount(2);
});

it('invalidates after the v2 bulkUpsert', function (): void {
    $this->forrest->shouldReceive('patch')->andReturn([['id' => '003A', 'success' => true, 'created' => false]]);

    CompatContact::get();
    expect($this->soql)->toHaveCount(1);

    CompatContact::query()->upsert([['Ext__c' => 'x', 'LastName' => 'Jones']], 'Ext__c');

    CompatContact::get();
    expect($this->soql)->toHaveCount(2);
});

it('bypasses the cache when a subquery touches an excluded object', function (): void {
    config()->set('salesforce-cache.exclude_objects', ['Contact']);

    CompatAccount::with('contacts')->get();
    CompatAccount::with('contacts')->get();

    expect($this->soql)->toHaveCount(2);
});

it('refetches a semi-join when the object it filters on is invalidated', function (): void {
    $query = fn () => CompatAccount::query()
        ->whereIn('Id', fn ($q) => $q->select('AccountId')->from('Contact'))
        ->get();

    $query();
    $query();
    expect($this->soql)->toHaveCount(1);

    app(CacheInvalidator::class)->invalidateObject('Contact');

    $query();
    expect($this->soql)->toHaveCount(2);
});

it('flushes every cached object on a write through the adapter', function (): void {
    $this->forrest->shouldReceive('sobjects')->andReturn(null);
    $adapter = app(SalesforceAdapter::class);

    $adapter->query('select Id from Lead');
    $adapter->update('Contact', '003A', ['LastName' => 'Jones']);
    $adapter->query('select Id from Lead');

    expect($this->soql)->toHaveCount(2);
});

it('does not treat quoted text as a subquery', function (): void {
    $adapter = app(SalesforceAdapter::class);
    $soql = "select Id from Account where Name = 'x (select Id from Contact) from Lead'";

    $adapter->query($soql);
    $adapter->query($soql);
    expect($this->soql)->toHaveCount(1);

    app(CacheInvalidator::class)->invalidateObject('Account');

    $adapter->query($soql);
    expect($this->soql)->toHaveCount(2);
});

it('does not cache a partial page whose query locator will expire', function (): void {
    $cachedQuery = new ReflectionMethod(CachedSalesforceAdapter::class, 'cachedQuery');
    $adapter = app(SalesforceAdapter::class);
    $calls = 0;

    $page = function () use (&$calls): array {
        $calls++;

        return ['records' => [['Id' => '001A']], 'totalSize' => 3000, 'done' => false, 'nextRecordsUrl' => '/next/1'];
    };

    $cachedQuery->invoke($adapter, 'query', 'select Id from Account', $page);
    $cachedQuery->invoke($adapter, 'query', 'select Id from Account', $page);

    expect($calls)->toBe(2);
});
