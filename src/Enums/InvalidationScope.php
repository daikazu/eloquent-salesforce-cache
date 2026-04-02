<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache\Enums;

enum InvalidationScope: string
{
    case Record = 'record';
    case Object = 'object';
    case All = 'all';
}
