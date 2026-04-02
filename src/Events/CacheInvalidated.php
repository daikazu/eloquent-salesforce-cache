<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache\Events;

use Daikazu\EloquentSalesforceCache\Enums\InvalidationScope;
use Illuminate\Foundation\Events\Dispatchable;

class CacheInvalidated
{
    use Dispatchable;

    /**
     * @param  string  $object  Salesforce object type (e.g., 'Opportunity') or '*' for all
     * @param  string[]  $ids  Record IDs invalidated (plain IDs only, empty for object/all scope)
     * @param  InvalidationScope  $scope  Invalidation scope
     * @param  array<array{object: string, id: string}>  $relatedRecords  Related records invalidated during cascade (empty for non-cascade operations)
     */
    public function __construct(
        public readonly string $object,
        public readonly array $ids = [],
        public readonly InvalidationScope $scope = InvalidationScope::Record,
        public readonly array $relatedRecords = [],
    ) {}
}
