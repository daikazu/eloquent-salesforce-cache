<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceCache\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInvalidationRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = config('salesforce-cache.api.api_key_header', 'X-Salesforce-Cache-Key');
        $expected = config('salesforce-cache.api.api_key');

        if (empty($expected) || ! hash_equals($expected, (string) $request->header($header))) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
