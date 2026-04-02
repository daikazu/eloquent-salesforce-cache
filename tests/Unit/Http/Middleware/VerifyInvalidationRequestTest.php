<?php

declare(strict_types=1);

use Daikazu\EloquentSalesforceCache\Http\Middleware\VerifyInvalidationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

beforeEach(function (): void {
    $this->middleware = new VerifyInvalidationRequest;
    $this->next = fn (Request $request): JsonResponse => response()->json(['ok' => true]);

    config()->set('salesforce-cache.api.api_key', 'secret-key');
    config()->set('salesforce-cache.api.api_key_header', 'X-Salesforce-Cache-Key');
});

it('allows valid api key', function (): void {
    $request = Request::create('/test', 'POST');
    $request->headers->set('X-Salesforce-Cache-Key', 'secret-key');

    $response = $this->middleware->handle($request, $this->next);

    expect(json_decode($response->getContent(), true))->toHaveKey('ok', true);
});

it('rejects invalid api key', function (): void {
    $request = Request::create('/test', 'POST');
    $request->headers->set('X-Salesforce-Cache-Key', 'wrong-key');

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
});

it('rejects missing api key header', function (): void {
    $request = Request::create('/test', 'POST');

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
});

it('rejects when configured key is empty (fail closed)', function (): void {
    config()->set('salesforce-cache.api.api_key', null);

    $request = Request::create('/test', 'POST');
    $request->headers->set('X-Salesforce-Cache-Key', 'any-key');

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
});
