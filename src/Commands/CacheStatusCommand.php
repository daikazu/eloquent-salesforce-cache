<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache\Commands;

use Daikazu\EloquentSalesforceCache\Support\TagRegistry;
use Illuminate\Console\Command;

class CacheStatusCommand extends Command
{
    protected $signature = 'salesforce-cache:status';

    protected $description = 'Show Salesforce cache status';

    public function handle(TagRegistry $registry): int
    {
        $this->components->info('Salesforce Cache Status');

        $this->table(
            ['Setting', 'Value'],
            [
                ['Cache Store', config('salesforce-cache.store', 'redis')],
                ['Caching', config('salesforce-cache.enabled') ? 'Enabled' : 'Disabled'],
                ['Default TTL', config('salesforce-cache.ttl', 1800) . 's'],
                ['API Endpoints', config('salesforce-cache.api.enabled') ? 'Enabled' : 'Disabled'],
                ['Auth Header', config('salesforce-cache.api.api_key_header', 'X-Salesforce-Cache-Key')],
            ]
        );

        $trackedObjects = $registry->getTrackedObjectTypes();

        if ($trackedObjects !== []) {
            $this->newLine();
            $this->components->info('Tracked Objects (Layer 2)');

            $rows = [];
            foreach ($trackedObjects as $object) {
                $rows[] = [$object, $registry->countForObject($object)];
            }

            $this->table(['Object Type', 'Tracked Records'], $rows);
        }

        return self::SUCCESS;
    }
}
