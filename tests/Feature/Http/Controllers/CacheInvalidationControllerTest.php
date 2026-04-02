<?php

declare(strict_types=1);

use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;
use Daikazu\EloquentSalesforceCache\Support\TagRegistry;

beforeEach(function (): void {
    $this->invalidator = Mockery::mock(CacheInvalidator::class);
    $this->app->instance(CacheInvalidator::class, $this->invalidator);

    $this->registry = Mockery::mock(TagRegistry::class);
    $this->app->instance(TagRegistry::class, $this->registry);

    $this->headers = ['X-Salesforce-Cache-Key' => 'test-api-key'];
});

describe('POST /invalidate', function (): void {
    it('invalidates records', function (): void {
        $this->invalidator->shouldReceive('invalidateMany')
            ->with([['object' => 'Opportunity', 'id' => '001']])
            ->once();

        $response = $this->postJson(
            'api/salesforce-cache/invalidate',
            ['records' => [['object' => 'Opportunity', 'id' => '001']]],
            $this->headers,
        );

        $response->assertOk()
            ->assertJson(['success' => true, 'with_relationships' => false]);
    });

    it('invalidates with relationships when requested', function (): void {
        $this->invalidator->shouldReceive('invalidateRecordWithRelationships')
            ->with('Opportunity', '001')
            ->once();

        $response = $this->postJson(
            'api/salesforce-cache/invalidate',
            [
                'records'            => [['object' => 'Opportunity', 'id' => '001']],
                'with_relationships' => true,
            ],
            $this->headers,
        );

        $response->assertOk()
            ->assertJson(['success' => true, 'with_relationships' => true]);
    });

    it('validates records are required', function (): void {
        $response = $this->postJson(
            'api/salesforce-cache/invalidate',
            [],
            $this->headers,
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    });

    it('validates max 100 records', function (): void {
        $records = array_fill(0, 101, ['object' => 'Opportunity', 'id' => '001']);

        $response = $this->postJson(
            'api/salesforce-cache/invalidate',
            ['records' => $records],
            $this->headers,
        );

        $response->assertStatus(422);
    });

    it('validates record structure', function (): void {
        $response = $this->postJson(
            'api/salesforce-cache/invalidate',
            ['records' => [['object' => 'Opportunity']]],
            $this->headers,
        );

        $response->assertStatus(422);
    });

    it('rejects without auth', function (): void {
        $response = $this->postJson(
            'api/salesforce-cache/invalidate',
            ['records' => [['object' => 'Opportunity', 'id' => '001']]],
        );

        $response->assertStatus(401);
    });
});

describe('POST /invalidate/object', function (): void {
    it('flushes object cache', function (): void {
        $this->invalidator->shouldReceive('invalidateObject')
            ->with('Opportunity')
            ->once();

        $response = $this->postJson(
            'api/salesforce-cache/invalidate/object',
            ['object' => 'Opportunity'],
            $this->headers,
        );

        $response->assertOk()
            ->assertJson(['success' => true]);
    });

    it('validates object is required', function (): void {
        $response = $this->postJson(
            'api/salesforce-cache/invalidate/object',
            [],
            $this->headers,
        );

        $response->assertStatus(422);
    });
});

describe('POST /flush', function (): void {
    it('flushes all cache', function (): void {
        $this->invalidator->shouldReceive('invalidateAll')->once();

        $response = $this->postJson(
            'api/salesforce-cache/flush',
            [],
            $this->headers,
        );

        $response->assertOk()
            ->assertJson(['success' => true]);
    });
});

describe('GET /status', function (): void {
    it('returns cache status', function (): void {
        $this->registry->shouldReceive('getTrackedObjectTypes')
            ->andReturn(['Opportunity', 'Account']);

        $response = $this->getJson(
            'api/salesforce-cache/status',
            $this->headers,
        );

        $response->assertOk()
            ->assertJson([
                'store'           => 'array',
                'adapter_enabled' => true,
                'registry_prefix' => 'sf_cache_registry',
                'tracked_objects' => ['Opportunity', 'Account'],
            ]);
    });
});
