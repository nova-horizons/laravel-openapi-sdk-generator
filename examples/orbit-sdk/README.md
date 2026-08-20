# Orbit SDK

Generated Laravel-native client for **Orbit** (spec v2.0.0).
Do not edit by hand — regenerate from the producer project.

## Setup

Configure and resolve — no service provider or binding needed (`#[Singleton]` + `#[Config]` attributes handle both):

```php
// config/services.php
'orbit' => [
    'url' => env('ORBIT_URL'),          // optional — defaults to https://api.example.com/v1
    'api_key' => env('ORBIT_API_KEY'),  // optional; also read: .timeout (seconds), .retries
],

$client = app(\Orbit\Sdk\OrbitClient::class);

// or explicitly:
$client = \Orbit\Sdk\OrbitClient::make(apiKey: '...');  // base URL defaults to https://api.example.com/v1
```

With no URL from config, `make()`, or the spec, the client throws `Exceptions\ConfigurationException` on first use — never a silent misdirected request.

`.timeout` is in seconds (Laravel's default 30s when unset). `.retries` is off unless set; when set, only safe failures retry — transport errors always, 5xx/429 on GETs only, with linear backoff — while other 4xx responses and non-idempotent requests surface immediately.

## Errors

Everything this SDK throws implements `Exceptions\OrbitException`:

| Exception | When |
| --- | --- |
| `Exceptions\RequestException` | 4xx/5xx response (extends Illuminate's; `->error()` returns the typed `Dto\Error` body) |
| `Exceptions\ConnectionException` | transport failure (extends Illuminate's) |
| `Exceptions\UnexpectedResponseException` | 2xx body that defies the spec — message includes the wire path |

## Testing

```php
use Orbit\Sdk\Testing\OrbitFake;

Http::fake([OrbitFake::<OPERATION> => Http::response(OrbitFake::<factory>([...overrides]))]);
```

Factories return wire-keyed arrays seeded from spec examples; `<factory>Dto()` variants return hydrated DTOs. Or bypass HTTP entirely with `OrbitClient::fromPendingRequest()`.

> **Fake patterns are method-blind.** `Http::fake()` matches URLs only, so operations on the same path (list vs create) share an identical pattern constant — as array keys, one silently overwrites the other — and a wildcard like `*/things/*` also matches deeper routes. Register more specific patterns first, and branch on `$request->method()` in a fake closure when two operations share a URL.

## Endpoints

### Beacons — `$client->beacons()`

| Method | Endpoint | Returns |
| --- | --- | --- |
| `listBeacons(…)` | GET `/beacons` | `Collection<Dto\Beacon>` |
| `createBeacon($body)` | POST `/beacons` | `Dto\Beacon` |
| `getBeacon($id)` | GET `/beacons/{id}` | `Dto\Beacon` |

### Telemetry — `$client->telemetry()`

| Method | Endpoint | Returns |
| --- | --- | --- |
| `telemetrySnapshot($id)` | GET `/beacons/{id}/telemetry` | `Dto\TelemetrySnapshotResponse` |
| `beaconUsage($id)` | GET `/beacons/{id}/usage` | `Dto\UsageReport` |

