<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache\Support;

use Daikazu\EloquentSalesforceCache\Concerns\CachesSalesforceQueries;
use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use Throwable;

class ModelDiscovery
{
    private const string CACHE_KEY = 'sf_cache:model_discovery';

    /** @var array<string, string[]>|null */
    private ?array $cascadeMap = null;

    /** @return string[] */
    public function getCascadeTargets(string $object): array
    {
        return $this->getMap()[$object] ?? [];
    }

    /** @return array<string, string[]> */
    private function getMap(): array
    {
        if ($this->cascadeMap !== null) {
            return $this->cascadeMap;
        }

        try {
            $cached = Cache::store(config('salesforce-cache.store'))->get(self::CACHE_KEY);

            if (is_array($cached)) {
                return $this->cascadeMap = $cached;
            }
        } catch (Throwable) {
            // Cache read failed — fall through to scan
        }

        $map = $this->scan();
        $this->cascadeMap = $map;

        try {
            Cache::store(config('salesforce-cache.store'))->put(
                self::CACHE_KEY,
                $map,
                (int) config('salesforce-cache.ttl', 1800) * 10,
            );
        } catch (Throwable) {
            // Cache write failed — map is still in memory for this request
        }

        return $map;
    }

    /** @return array<string, string[]> */
    private function scan(): array
    {
        $map = [];

        foreach ($this->getDiscoverableClasses() as $class) {
            try {
                $reflection = new ReflectionClass($class);

                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                if (! $reflection->isSubclassOf(SalesforceModel::class)) {
                    continue;
                }

                if (! $this->usesTrait($reflection, CachesSalesforceQueries::class)) {
                    continue;
                }

                // Filter by model_paths if configured
                $modelPaths = config('salesforce-cache.model_paths');
                if (is_array($modelPaths)) {
                    $fileName = $reflection->getFileName();
                    if ($fileName === false) {
                        continue;
                    }

                    $inPath = false;
                    foreach ($modelPaths as $path) {
                        if (str_starts_with($fileName, $path)) {
                            $inPath = true;
                            break;
                        }
                    }

                    if (! $inPath) {
                        continue;
                    }
                }

                $instance = $reflection->newInstanceWithoutConstructor();

                if (! method_exists($instance, 'getInvalidatesObjects')) {
                    continue;
                }

                $targets = $instance->getInvalidatesObjects();

                if ($targets !== []) {
                    $map[$instance->getTable()] = $targets;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $map;
    }

    private function usesTrait(ReflectionClass $reflection, string $trait): bool
    {
        $current = $reflection;

        while ($current) {
            if (in_array($trait, $current->getTraitNames(), true)) {
                return true;
            }
            $current = $current->getParentClass() ?: null;
        }

        return false;
    }

    /** @return string[] */
    protected function getDiscoverableClasses(): array
    {
        return array_filter(
            get_declared_classes(),
            fn (string $class): bool => ! str_starts_with($class, 'Illuminate\\')
                && ! str_starts_with($class, 'Symfony\\')
                && ! str_starts_with($class, 'Composer\\')
                && ! str_starts_with($class, 'PhpParser\\')
                && ! str_starts_with($class, 'PHPUnit\\')
                && ! str_starts_with($class, 'Pest\\'),
        );
    }
}
