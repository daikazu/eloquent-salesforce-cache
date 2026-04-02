<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache\Http\Controllers;

use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;
use Daikazu\EloquentSalesforceCache\Support\TagRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CacheInvalidationController extends Controller
{
    public function __construct(
        private readonly CacheInvalidator $invalidator,
    ) {}

    public function invalidate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'records'            => 'required|array|min:1|max:100',
            'records.*.object'   => 'required|string|max:255',
            'records.*.id'       => 'required|string|max:255',
            'with_relationships' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid payload',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $records = $request->input('records');
            $withRelationships = $request->boolean('with_relationships');

            if ($withRelationships) {
                foreach ($records as $record) {
                    $this->invalidator->invalidateRecordWithRelationships($record['object'], $record['id']);
                }
            } else {
                $this->invalidator->invalidateMany($records);
            }

            return response()->json([
                'success'            => true,
                'message'            => sprintf('Invalidated %d records', count($records)),
                'invalidated'        => $records,
                'with_relationships' => $withRelationships,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalidation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function invalidateObject(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'object' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $this->invalidator->invalidateObject($request->input('object'));

        return response()->json([
            'success' => true,
            'message' => sprintf('All cache flushed for %s', $request->input('object')),
        ]);
    }

    public function flush(): JsonResponse
    {
        $this->invalidator->invalidateAll();

        return response()->json([
            'success' => true,
            'message' => 'All Salesforce cache flushed',
        ]);
    }

    public function status(): JsonResponse
    {
        $registry = app(TagRegistry::class);

        return response()->json([
            'store'           => config('salesforce-cache.store'),
            'adapter_enabled' => config('salesforce-cache.enabled'),
            'registry_prefix' => config('salesforce-cache.registry_prefix'),
            'tracked_objects' => $registry->getTrackedObjectTypes(),
        ]);
    }
}
