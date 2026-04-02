# Cache Invalidation API

This document covers every method of triggering cache invalidation in `daikazu/eloquent-salesforce-cache`: HTTP API endpoints, Artisan commands, the `CacheInvalidator` service, and the `CachesSalesforceQueries` trait methods. It also includes a guide for connecting Salesforce outbound messages or Platform Events to the invalidation endpoint.

---

## Table of Contents

- [HTTP API Endpoints](#http-api-endpoints)
  - [Authentication Setup](#authentication-setup)
  - [POST /invalidate — Invalidate specific records](#post-invalidate)
  - [POST /invalidate/object — Flush an entire object type](#post-invalidateobject)
  - [POST /flush — Flush all Salesforce cache](#post-flush)
  - [GET /status — Cache status](#get-status)
- [Artisan Commands](#artisan-commands)
- [CacheInvalidator Service](#cacheinvalidator-service)
- [Trait Methods (CachesSalesforceQueries)](#trait-methods)
- [CacheInvalidated Event](#cacheinvalidated-event)
- [Webhook Integration Guide](#webhook-integration-guide)

---

## HTTP API Endpoints

All endpoints are registered under the `api.prefix` configuration key (default: `api/salesforce-cache`). They are protected by both the standard `api` middleware stack and the `VerifyInvalidationRequest` middleware.

Base URL pattern: `https://your-app.example.com/api/salesforce-cache`

**Route list:**

| Method | Path | Description |
|---|---|---|
| `POST` | `/api/salesforce-cache/invalidate` | Invalidate one or more records by object type and ID |
| `POST` | `/api/salesforce-cache/invalidate/object` | Flush all cached queries for an object type |
| `POST` | `/api/salesforce-cache/flush` | Flush all Salesforce cache |
| `GET` | `/api/salesforce-cache/status` | Return configuration and tracked object summary |

All routes can be renamed with `api.prefix` in `config/salesforce-cache.php`. The API can be disabled entirely by setting `SALESFORCE_CACHE_API_ENABLED=false`.

---

### Authentication Setup

Every request to the API must pass the `VerifyInvalidationRequest` middleware, which verifies an API key sent via HTTP header.

Add the key to your environment:

```dotenv
SALESFORCE_CACHE_API_KEY=a-long-random-secret-string
```

Include the key in every request using the `X-Salesforce-Cache-Key` header (configurable via `api.api_key_header`):

```bash
curl -s -X POST https://your-app.example.com/api/salesforce-cache/invalidate \
  -H "Content-Type: application/json" \
  -H "X-Salesforce-Cache-Key: a-long-random-secret-string" \
  -d '{"records": [{"object": "Opportunity", "id": "0061a00000AbCdEfG"}]}'
```

Requests with a missing or incorrect key receive a `401 Unauthorized` response:

```json
{
    "message": "Unauthorized"
}
```

If `SALESFORCE_CACHE_API_KEY` is empty or unset, all requests are rejected (fail closed).

---

### POST /invalidate

Invalidates cache entries for one or more specific records. Accepts up to 100 records per request.

**URL:** `POST /api/salesforce-cache/invalidate`

**Request body:**

| Field | Type | Required | Constraints | Description |
|---|---|---|---|---|
| `records` | `array` | Yes | 1–100 items | Array of records to invalidate |
| `records[].object` | `string` | Yes | max 255 chars | Salesforce object API name |
| `records[].id` | `string` | Yes | max 255 chars | Salesforce record ID |
| `with_relationships` | `boolean` | No | — | When `true`, cascades invalidation to all tracked related records for each record. Defaults to `false`. |

**Example request — single record:**

```bash
curl -s -X POST https://your-app.example.com/api/salesforce-cache/invalidate \
  -H "Content-Type: application/json" \
  -H "X-Salesforce-Cache-Key: your-api-key" \
  -d '{
    "records": [
      {"object": "Opportunity", "id": "0061a00000AbCdEfG"}
    ]
  }'
```

**Example request — multiple records:**

```bash
curl -s -X POST https://your-app.example.com/api/salesforce-cache/invalidate \
  -H "Content-Type: application/json" \
  -H "X-Salesforce-Cache-Key: your-api-key" \
  -d '{
    "records": [
      {"object": "Opportunity",         "id": "0061a00000AbCdEfG"},
      {"object": "OpportunityLineItem", "id": "00k1a00000XyZwVu"},
      {"object": "NEILON__File__c",     "id": "a0C1a000004AbCdE"}
    ]
  }'
```

**Example request — with relationship cascade:**

```bash
curl -s -X POST https://your-app.example.com/api/salesforce-cache/invalidate \
  -H "Content-Type: application/json" \
  -H "X-Salesforce-Cache-Key: your-api-key" \
  -d '{
    "records": [
      {"object": "Opportunity", "id": "0061a00000AbCdEfG"}
    ],
    "with_relationships": true
  }'
```

When `with_relationships` is `true`, each record is invalidated using `invalidateRecordWithRelationships()`, which cascades to all related records tracked in the `TagRegistry`. This is one level deep only (parent -> children).

**Success response — 200 OK:**

```json
{
    "success": true,
    "message": "Invalidated 3 records",
    "invalidated": [
        {"object": "Opportunity",         "id": "0061a00000AbCdEfG"},
        {"object": "OpportunityLineItem", "id": "00k1a00000XyZwVu"},
        {"object": "NEILON__File__c",     "id": "a0C1a000004AbCdE"}
    ],
    "with_relationships": false
}
```

**Validation error response — 422 Unprocessable Entity:**

```json
{
    "success": false,
    "message": "Invalid payload",
    "errors": {
        "records.0.id": ["The records.0.id field is required."]
    }
}
```

**Server error response — 500 Internal Server Error:**

```json
{
    "success": false,
    "message": "Invalidation failed: Connection refused"
}
```

Note: Because `CacheInvalidator` methods degrade gracefully, a 500 response from this endpoint indicates an unexpected, non-Redis failure. Redis connection failures are caught internally and logged rather than propagated as HTTP errors.

---

### POST /invalidate/object

Flushes all cached SOQL queries for a given Salesforce object type. This is equivalent to the write-through invalidation that happens automatically when a record is mutated through the adapter.

**URL:** `POST /api/salesforce-cache/invalidate/object`

**Request body:**

| Field | Type | Required | Description |
|---|---|---|---|
| `object` | `string` | Yes | Salesforce object API name (e.g., `Opportunity`) |

**Example request:**

```bash
curl -s -X POST https://your-app.example.com/api/salesforce-cache/invalidate/object \
  -H "Content-Type: application/json" \
  -H "X-Salesforce-Cache-Key: your-api-key" \
  -d '{"object": "Opportunity"}'
```

**Success response — 200 OK:**

```json
{
    "success": true,
    "message": "All cache flushed for Opportunity"
}
```

**Validation error response — 422 Unprocessable Entity:**

```json
{
    "success": false,
    "errors": {
        "object": ["The object field is required."]
    }
}
```

---

### POST /flush

Flushes the entire `salesforce` cache tag, removing all cached SOQL query results across all object types. Also clears all `TagRegistry` entries.

Use this endpoint cautiously in production; it will cause a burst of live Salesforce API calls as the cache is rebuilt.

**URL:** `POST /api/salesforce-cache/flush`

**Request body:** None required.

**Example request:**

```bash
curl -s -X POST https://your-app.example.com/api/salesforce-cache/flush \
  -H "X-Salesforce-Cache-Key: your-api-key"
```

**Success response — 200 OK:**

```json
{
    "success": true,
    "message": "All Salesforce cache flushed"
}
```

---

### GET /status

Returns the current configuration and a summary of object types tracked in the Layer 2 registry.

**URL:** `GET /api/salesforce-cache/status`

**Example request:**

```bash
curl -s https://your-app.example.com/api/salesforce-cache/status \
  -H "X-Salesforce-Cache-Key: your-api-key"
```

**Success response — 200 OK:**

```json
{
    "store": "redis",
    "adapter_enabled": true,
    "registry_prefix": "sf_cache_registry",
    "tracked_objects": [
        "Opportunity",
        "OpportunityLineItem",
        "NEILON__File__c"
    ]
}
```

`tracked_objects` contains only the object types that have been accessed at least once since the last full flush (i.e., objects that have entries in the `TagRegistry`). An empty array means no Layer 2 records have been hydrated since the last flush, not that caching is inactive.

---

## Artisan Commands

Artisan commands are available for use in deployments, cron jobs, and local debugging.

### salesforce-cache:invalidate

```
Description:
  Invalidate Salesforce cache entries

Usage:
  salesforce-cache:invalidate [options]

Options:
  --record=*             Record to invalidate in format ObjectType:Id (repeatable)
  --object=              Flush all cache for a specific object type
  --with-relationships   Cascade invalidation to tracked related records
  --all                  Flush all Salesforce cache
```

When called without any options, the command enters interactive mode with a menu to choose the invalidation scope.

**Invalidate a single record:**

```bash
php artisan salesforce-cache:invalidate --record=Opportunity:0061a00000AbCdEfG
```

**Invalidate a single record and all its tracked related records:**

```bash
php artisan salesforce-cache:invalidate \
  --record=Opportunity:0061a00000AbCdEfG \
  --with-relationships
```

The `--with-relationships` flag consults the `TagRegistry`. It only cascades to records that were registered during prior query hydration via the `CachesSalesforceQueries` trait. If the record has not been hydrated since the last flush, no related records will be found.

**Invalidate multiple records:**

```bash
php artisan salesforce-cache:invalidate \
  --record=Opportunity:0061a00000AbCdEfG \
  --record=OpportunityLineItem:00k1a00000XyZwVu
```

**Flush all cache for an object type:**

```bash
php artisan salesforce-cache:invalidate --object=Opportunity
```

**Flush all Salesforce cache (with confirmation prompt):**

```bash
php artisan salesforce-cache:invalidate --all
```

**Flush all Salesforce cache without confirmation (suitable for scripts):**

```bash
php artisan salesforce-cache:invalidate --all --no-interaction
```

**Error output:** If a `--record` value does not contain a colon separator, the command exits with a failure status and prints an error:

```
 ERROR  Invalid format: 'Opportunity'. Expected ObjectType:Id
```

---

### salesforce-cache:status

```
Description:
  Show Salesforce cache status

Usage:
  salesforce-cache:status
```

Displays the current configuration and the tracked objects registered in the Layer 2 `TagRegistry`.

```bash
php artisan salesforce-cache:status
```

Example output:

```
 INFO  Salesforce Cache Status

 ------------------- ---------
  Setting             Value
 ------------------- ---------
  Cache Store         redis
  Caching             Enabled
  Default TTL         1800s
  Registry            Enabled
  API Endpoints       Enabled
  Auth Header         X-Salesforce-Cache-Key
 ------------------- ---------

 INFO  Tracked Objects (Layer 2)

 ----------------------- -----------------
  Object Type             Tracked Records
 ----------------------- -----------------
  Opportunity             42
  OpportunityLineItem     187
 ----------------------- -----------------
```

If no Layer 2 records are tracked (either because the trait is not in use or the registry was recently flushed), the "Tracked Objects" section is omitted.

---

## CacheInvalidator Service

`CacheInvalidator` is the single internal entry point for all invalidation logic. The HTTP controller, Artisan commands, and trait methods all delegate to it. You can also inject or resolve it directly in your own service classes.

```php
use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;

// Resolve from the container
$invalidator = app(CacheInvalidator::class);

// Or inject via constructor
class MyService
{
    public function __construct(private readonly CacheInvalidator $invalidator) {}
}
```

### Method Reference

---

**`invalidateRecord(string $object, string $id): void`**

Flushes all cache entries tagged with `{object}:{id}` and removes the record from the `TagRegistry`. Dispatches `CacheInvalidated` with `scope = 'record'`.

```php
$invalidator->invalidateRecord('Opportunity', '0061a00000AbCdEfG');
```

---

**`invalidateRecordWithRelationships(string $object, string $id): void`**

Flushes the specified record plus all related records tracked in the `TagRegistry`. Related records are those registered via `registerRelationship()` when the model's relationships were accessed after hydration (requires `CachesSalesforceQueries` trait with `$trackedRelationships` defined). Cascade is one level deep (parent -> children only). Dispatches `CacheInvalidated` with `scope = InvalidationScope::Record`, `$ids` containing the parent record ID, and `$relatedRecords` containing the cascaded related records as `['object' => ..., 'id' => ...]` arrays.

```php
$invalidator->invalidateRecordWithRelationships('Opportunity', '0061a00000AbCdEfG');
```

---

**`invalidateObject(string $object): void`**

Flushes all cache entries tagged with the object name. Does not affect the `TagRegistry`. Dispatches `CacheInvalidated` with `scope = 'object'`.

```php
$invalidator->invalidateObject('Opportunity');
```

---

**`invalidateMany(array $records): void`**

Invalidates each record individually, then groups by object type and dispatches one `CacheInvalidated` event per object type (not per record). Each element must be an associative array with `object` and `id` string keys.

```php
$invalidator->invalidateMany([
    ['object' => 'Opportunity',         'id' => '0061a00000AbCdEfG'],
    ['object' => 'Opportunity',         'id' => '0061a00000HiJkLmN'],
    ['object' => 'OpportunityLineItem', 'id' => '00k1a00000XyZwVu'],
]);
// Dispatches 2 events: one for Opportunity (2 IDs), one for OpportunityLineItem (1 ID)
```

---

**`invalidateAll(): void`**

Flushes everything tagged with `salesforce` and calls `TagRegistry::flush()` to remove all registry keys. Dispatches `CacheInvalidated` with `object = '*'` and `scope = 'all'`.

```php
$invalidator->invalidateAll();
```

---

## Trait Methods

Models that use the `CachesSalesforceQueries` trait gain both instance and static invalidation methods.

```php
use Daikazu\EloquentSalesforceCache\Concerns\CachesSalesforceQueries;

class Opportunity extends SalesforceObject
{
    use CachesSalesforceQueries;
}
```

### Instance Methods

---

**`invalidateCache(): void`**

Flushes all cache entries for this model's record ID. Delegates to `invalidateCacheFor()`.

```php
$opportunity = Opportunity::find('0061a00000AbCdEfG');
$opportunity->invalidateCache();
```

---

**`invalidateCacheWithRelationships(): void`**

Flushes this model's cache entries and all related records tracked in the `TagRegistry`. Reads relationship mappings using `TagRegistry::getRelatedRecords()` and removes the registry entries for all affected records.

```php
$opportunity->invalidateCacheWithRelationships();
```

### Static Methods

---

**`invalidateCacheFor(string $id): void`**

Flushes the cache tag `{object}:{id}` and removes the record from the registry.

```php
Opportunity::invalidateCacheFor('0061a00000AbCdEfG');
```

---

**`invalidateCacheForMany(array $ids): void`**

Calls `invalidateCacheFor()` for each ID in the array.

```php
Opportunity::invalidateCacheForMany([
    '0061a00000AbCdEfG',
    '0061a00000HiJkLmN',
]);
```

---

**`flushAllCache(): void`**

Flushes all cache entries tagged with this object's name (equivalent to `CacheInvalidator::invalidateObject()`). Does not clear the registry.

```php
Opportunity::flushAllCache();
```

---

## CacheInvalidated Event

`Daikazu\EloquentSalesforceCache\Events\CacheInvalidated` is dispatched after every invalidation operation.

**Constructor signature:**

```php
use Daikazu\EloquentSalesforceCache\Enums\InvalidationScope;

public function __construct(
    public readonly string $object,                                    // Salesforce object type, or '*' for scope=all
    public readonly array  $ids = [],                                  // Invalidated record IDs (plain IDs only); empty for object/all scope
    public readonly InvalidationScope $scope = InvalidationScope::Record, // Invalidation scope enum
    public readonly array  $relatedRecords = [],                       // Related records invalidated during cascade (empty for non-cascade)
)
```

The `$scope` property uses the `InvalidationScope` enum (string-backed: `'record'`, `'object'`, `'all'`).

The `$relatedRecords` property is populated only by `invalidateRecordWithRelationships()` and contains an array of `['object' => string, 'id' => string]` for each cascaded related record.

**Scope values by operation:**

| Operation | `$scope` | `$object` | `$ids` | `$relatedRecords` |
|---|---|---|---|---|
| `invalidateRecord()` | `Record` | Object name | `[id]` | `[]` |
| `invalidateRecordWithRelationships()` | `Record` | Object name | `[parent_id]` | `[['object' => ..., 'id' => ...], ...]` |
| `invalidateObject()` | `Object` | Object name | `[]` | `[]` |
| `invalidateAll()` | `All` | `'*'` | `[]` | `[]` |
| `invalidateMany()` | `Record` | Object name | IDs grouped by object (one event per object type) | `[]` |

**Registering a listener:**

```php
// app/Providers/EventServiceProvider.php
use Daikazu\EloquentSalesforceCache\Events\CacheInvalidated;

protected $listen = [
    CacheInvalidated::class => [
        \App\Listeners\HandleCacheInvalidated::class,
    ],
];
```

```php
// app/Listeners/HandleCacheInvalidated.php
namespace App\Listeners;

use Daikazu\EloquentSalesforceCache\Events\CacheInvalidated;
use Illuminate\Support\Facades\Log;

class HandleCacheInvalidated
{
    public function handle(CacheInvalidated $event): void
    {
        Log::channel('salesforce')->info('Cache invalidated', [
            'object' => $event->object,
            'ids'    => $event->ids,
            'scope'  => $event->scope,
        ]);
    }
}
```

**Using a queued listener** to defer work after invalidation:

```php
use Daikazu\EloquentSalesforceCache\Enums\InvalidationScope;
use Illuminate\Contracts\Queue\ShouldQueue;

class SyncSalesforceAfterInvalidation implements ShouldQueue
{
    public string $queue = 'salesforce';

    public function handle(CacheInvalidated $event): void
    {
        if ($event->scope === InvalidationScope::Record) {
            foreach ($event->ids as $id) {
                RefreshSalesforceRecord::dispatch($event->object, $id);
            }
        }
    }
}
```

---

## Webhook Integration Guide

This section explains how to connect Salesforce to your application's invalidation endpoint so that cache is automatically cleared when records change in Salesforce.

### Option A: Salesforce Outbound Messages

Outbound Messages are a declarative Salesforce feature that sends an HTTP POST to your endpoint when a workflow rule or flow fires.

**Limitations:**
- Outbound Messages send XML payloads (SOAP format), not JSON.
- The endpoint must respond with a specific acknowledgement XML.
- You will need a small adapter controller in your application to receive the message, extract the record ID, and call the invalidation API internally.

**Recommended approach — build a thin adapter:**

```php
// app/Http/Controllers/SalesforceWebhookController.php
namespace App\Http\Controllers;

use Daikazu\EloquentSalesforceCache\Services\CacheInvalidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SalesforceWebhookController extends Controller
{
    public function __construct(private readonly CacheInvalidator $invalidator) {}

    public function outboundMessage(Request $request): Response
    {
        // Parse the SOAP body and extract the object type and ID.
        // The exact XML structure depends on your outbound message configuration.
        $xml = simplexml_load_string($request->getContent());
        $ns  = $xml->getNamespaces(true);

        // Example path — adjust to your actual SOAP structure.
        $notifications = $xml->children($ns['soapenv'] ?? '')
                             ->Body
                             ->children()
                             ->notifications;

        foreach ($notifications->Notification as $notification) {
            $sfObject = (string) $notification->sObject->attributes('xsi', true)['type'];
            $id       = (string) $notification->sObject->Id;
            $this->invalidator->invalidateRecord($sfObject, $id);
        }

        // Salesforce requires this acknowledgement response.
        return response(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soapenv:Body><notificationsResponse xmlns="http://soap.sforce.com/2005/09/outbound">'
            . '<Ack>true</Ack></notificationsResponse></soapenv:Body></soapenv:Envelope>',
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
```

Register the route (outside the `VerifyInvalidationRequest` middleware since Salesforce cannot produce an `X-Salesforce-Cache-Key` header in outbound messages):

```php
// routes/api.php
Route::post('webhooks/salesforce', [SalesforceWebhookController::class, 'outboundMessage'])
    ->middleware(['api', \App\Http\Middleware\VerifySalesforceIp::class]);
```

Use IP allowlisting via Salesforce's published IP ranges as the authentication mechanism for outbound messages.

---

### Option B: Salesforce Platform Events and a Middleware Layer

Platform Events provide a pub/sub model. A subscriber (e.g., a Node.js or Python worker, or Laravel's queue via a Salesforce event consumer package) listens for events and calls your invalidation endpoint.

**Flow:**

```
Salesforce record changes
  --> Platform Event published (via trigger/flow)
    --> Subscriber service receives event
      --> POST /api/salesforce-cache/invalidate
        --> CacheInvalidator::invalidateRecord()
          --> CacheInvalidated event dispatched
```

**Subscriber calling the invalidation endpoint:**

This is a standard HTTP call from any language. Example in Node.js:

```js
const axios = require('axios');

async function onSalesforceEvent(event) {
  const { ObjectType__c, RecordId__c } = event.payload;

  await axios.post('https://your-app.example.com/api/salesforce-cache/invalidate', {
    records: [{ object: ObjectType__c, id: RecordId__c }],
  }, {
    headers: { 'X-Salesforce-Cache-Key': process.env.SALESFORCE_CACHE_API_KEY },
  });
}
```

---

### Option C: Salesforce Flow with HTTP Callout

Salesforce Flows can make HTTP callouts using the `HTTP Callout` action (available in API version 59.0+). This allows a Flow triggered by a record change to call your endpoint directly.

**Configuration in Salesforce Flow:**

1. Create an HTTP Callout named connection pointing to `https://your-app.example.com/api/salesforce-cache/invalidate`.
2. Set method to `POST`, content type to `application/json`.
3. Add a custom header: `X-Salesforce-Cache-Key` with the value stored in a Custom Metadata Type or a Protected Custom Setting (never hardcoded in the Flow).
4. Set the request body to a JSON template using the triggering record's object type and ID.

**Request body template for the Flow callout:**

```json
{
    "records": [
        {
            "object": "{!$Record__Prior.attributes.type}",
            "id": "{!$Record.Id}"
        }
    ]
}
```

**Security note:** Store the API key in a Protected Custom Setting or Custom Metadata Type with visibility restricted to the Salesforce org's system context. Do not store it as a plain text constant in the Flow.

---

### Choosing the Right Option

| Option | Complexity | Auth mechanism | Payload format | Best for |
|---|---|---|---|---|
| Outbound Messages | Medium (adapter controller needed) | IP allowlist | SOAP/XML | Simple field-change triggers on standard objects |
| Platform Events + subscriber | Higher (separate worker process) | API key | JSON | High-volume changes, complex event routing |
| Flow HTTP Callout | Low (declarative in Salesforce) | API key in Protected Custom Setting | JSON | Modern Salesforce orgs, straightforward record-change invalidation |

For most use cases, the Flow HTTP Callout option (Option C) is the simplest to set up and maintain.

---

### Verifying Webhook Delivery

After configuring any webhook integration, use the status endpoint to confirm that invalidations are reaching your application:

```bash
# Before triggering a change in Salesforce
curl -s https://your-app.example.com/api/salesforce-cache/status \
  -H "X-Salesforce-Cache-Key: your-api-key"

# Trigger a record change in Salesforce, then check again
# You should see the object disappear from tracked_objects if the registry was cleared,
# or use application logs (SALESFORCE_CACHE_LOG_ENABLED=true) to observe invalidation events.
```

Enable logging during testing to observe invalidation events as they arrive:

```dotenv
SALESFORCE_CACHE_LOG_ENABLED=true
SALESFORCE_CACHE_LOG_CHANNEL=stderr
```

Then tail the log while triggering changes:

```bash
php artisan salesforce-cache:status
# Trigger a Salesforce change
tail -f storage/logs/laravel.log | grep SalesforceCache
```
