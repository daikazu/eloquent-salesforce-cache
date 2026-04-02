<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache\Commands;

use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class InvalidateCacheCommand extends Command
{
    protected $signature = 'salesforce-cache:invalidate
                            {--record=* : Record to invalidate (format: ObjectType:Id)}
                            {--object= : Flush all cache for an object type}
                            {--with-relationships : Cascade to related records}
                            {--all : Flush all Salesforce cache}';

    protected $description = 'Invalidate Salesforce cache entries';

    public function handle(CacheInvalidator $invalidator): int
    {
        if ($this->option('all')) {
            if (! $this->option('no-interaction') && ! confirm(
                label: 'Flush ALL Salesforce cache?',
                default: false,
            )) {
                $this->components->info('Cancelled');

                return self::SUCCESS;
            }

            $invalidator->invalidateAll();
            $this->components->success('All Salesforce cache flushed');

            return self::SUCCESS;
        }

        if ($object = $this->option('object')) {
            $invalidator->invalidateObject($object);
            $this->components->success("Cache flushed for {$object}");

            return self::SUCCESS;
        }

        $records = $this->option('record');

        if (! empty($records)) {
            foreach ($records as $record) {
                if (! str_contains((string) $record, ':')) {
                    $this->components->error("Invalid format: '{$record}'. Expected ObjectType:Id");

                    return self::FAILURE;
                }

                [$object, $id] = explode(':', (string) $record, 2);

                if ($this->option('with-relationships')) {
                    $invalidator->invalidateRecordWithRelationships($object, $id);
                    $this->components->info("Invalidated {$object}:{$id} with relationships");
                } else {
                    $invalidator->invalidateRecord($object, $id);
                    $this->components->info("Invalidated {$object}:{$id}");
                }
            }

            $this->components->success(sprintf('Invalidated %d record(s)', count($records)));

            return self::SUCCESS;
        }

        // Interactive mode
        $action = select(
            label: 'What would you like to invalidate?',
            options: [
                'record' => 'Specific record(s)',
                'object' => 'All cache for an object type',
                'all'    => 'All Salesforce cache',
            ],
        );

        return match ($action) {
            'record' => $this->interactiveRecord($invalidator),
            'object' => $this->interactiveObject($invalidator),
            'all'    => $this->interactiveAll($invalidator),
            default  => self::FAILURE,
        };
    }

    private function interactiveRecord(CacheInvalidator $invalidator): int
    {
        $input = text(
            label: 'Enter record (ObjectType:Id)',
            required: true,
            validate: fn (string $value): ?string => str_contains($value, ':') ? null : 'Format must be ObjectType:Id',
        );

        [$object, $id] = explode(':', $input, 2);
        $withRelationships = confirm('Include relationships?', false);

        if ($withRelationships) {
            $invalidator->invalidateRecordWithRelationships($object, $id);
        } else {
            $invalidator->invalidateRecord($object, $id);
        }

        $this->components->success("Invalidated {$input}");

        return self::SUCCESS;
    }

    private function interactiveObject(CacheInvalidator $invalidator): int
    {
        $object = text(label: 'Object type name', required: true);
        $invalidator->invalidateObject($object);
        $this->components->success("Cache flushed for {$object}");

        return self::SUCCESS;
    }

    private function interactiveAll(CacheInvalidator $invalidator): int
    {
        if (! confirm('Flush ALL Salesforce cache?', false)) {
            $this->components->info('Cancelled');

            return self::SUCCESS;
        }

        $invalidator->invalidateAll();
        $this->components->success('All Salesforce cache flushed');

        return self::SUCCESS;
    }
}
