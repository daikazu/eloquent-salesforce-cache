<?php

declare(strict_types=1);

use Daikazu\EloquentSalesforceCache\Enums\InvalidationScope;

it('has record scope', function (): void {
    expect(InvalidationScope::Record->value)->toBe('record');
});

it('has object scope', function (): void {
    expect(InvalidationScope::Object->value)->toBe('object');
});

it('has all scope', function (): void {
    expect(InvalidationScope::All->value)->toBe('all');
});

it('can be created from string value', function (): void {
    expect(InvalidationScope::from('record'))->toBe(InvalidationScope::Record);
    expect(InvalidationScope::from('object'))->toBe(InvalidationScope::Object);
    expect(InvalidationScope::from('all'))->toBe(InvalidationScope::All);
});

it('throws for invalid value', function (): void {
    InvalidationScope::from('invalid');
})->throws(ValueError::class);
