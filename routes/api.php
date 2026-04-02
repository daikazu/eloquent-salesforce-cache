<?php

declare(strict_types=1);

use Daikazu\EloquentSalesforceCache\Http\Controllers\CacheInvalidationController;
use Daikazu\EloquentSalesforceCache\Http\Middleware\VerifyInvalidationRequest;
use Illuminate\Support\Facades\Route;

Route::middleware([...config('salesforce-cache.api.middleware', ['api']), VerifyInvalidationRequest::class])
    ->prefix(config('salesforce-cache.api.prefix', 'api/salesforce-cache'))
    ->group(function (): void {
        Route::post('invalidate', [CacheInvalidationController::class, 'invalidate'])
            ->name('salesforce-cache.invalidate');

        Route::post('invalidate/object', [CacheInvalidationController::class, 'invalidateObject'])
            ->name('salesforce-cache.invalidate.object');

        Route::post('flush', [CacheInvalidationController::class, 'flush'])
            ->name('salesforce-cache.flush');

        Route::get('status', [CacheInvalidationController::class, 'status'])
            ->name('salesforce-cache.status');
    });
