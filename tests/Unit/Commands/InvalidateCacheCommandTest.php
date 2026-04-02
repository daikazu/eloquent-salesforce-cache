<?php

declare(strict_types=1);

use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;

beforeEach(function (): void {
    $this->invalidator = Mockery::mock(CacheInvalidator::class);
    $this->app->instance(CacheInvalidator::class, $this->invalidator);
});

it('invalidates a single record', function (): void {
    $this->invalidator->shouldReceive('invalidateRecord')
        ->with('Opportunity', '001')
        ->once();

    $this->artisan('salesforce-cache:invalidate', ['--record' => ['Opportunity:001']])
        ->assertSuccessful();
});

it('invalidates multiple records', function (): void {
    $this->invalidator->shouldReceive('invalidateRecord')
        ->with('Opportunity', '001')
        ->once();
    $this->invalidator->shouldReceive('invalidateRecord')
        ->with('Account', '002')
        ->once();

    $this->artisan('salesforce-cache:invalidate', [
        '--record' => ['Opportunity:001', 'Account:002'],
    ])->assertSuccessful();
});

it('invalidates record with relationships', function (): void {
    $this->invalidator->shouldReceive('invalidateRecordWithRelationships')
        ->with('Opportunity', '001')
        ->once();

    $this->artisan('salesforce-cache:invalidate', [
        '--record'             => ['Opportunity:001'],
        '--with-relationships' => true,
    ])->assertSuccessful();
});

it('fails on invalid record format', function (): void {
    $this->artisan('salesforce-cache:invalidate', ['--record' => ['InvalidFormat']])
        ->assertFailed();
});

it('invalidates by object type', function (): void {
    $this->invalidator->shouldReceive('invalidateObject')
        ->with('Opportunity')
        ->once();

    $this->artisan('salesforce-cache:invalidate', ['--object' => 'Opportunity'])
        ->assertSuccessful();
});

it('flushes all cache with no-interaction', function (): void {
    $this->invalidator->shouldReceive('invalidateAll')->once();

    $this->artisan('salesforce-cache:invalidate', [
        '--all'            => true,
        '--no-interaction' => true,
    ])->assertSuccessful();
});
