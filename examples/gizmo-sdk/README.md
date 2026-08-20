# Gizmo Works API SDK

Generated Laravel-native client for **Gizmo Works API** (spec v1.4.0).
Do not edit by hand — regenerate from the producer project.

## Setup

Configure and resolve — no service provider or binding needed (`#[Singleton]` + `#[Config]` attributes handle both):

```php
// config/services.php
'gizmo' => [
    'url' => env('GIZMO_URL'),          // optional — defaults to https://api.example.com/v1
    'api_key' => env('GIZMO_API_KEY'),  // optional; also read: .timeout (seconds), .retries
],

$client = app(\Gizmo\Sdk\GizmoClient::class);

// or explicitly:
$client = \Gizmo\Sdk\GizmoClient::make(apiKey: '...');  // base URL defaults to https://api.example.com/v1
```

With no URL from config, `make()`, or the spec, the client throws `Exceptions\ConfigurationException` on first use — never a silent misdirected request.

`.timeout` is in seconds (Laravel's default 30s when unset). `.retries` is off unless set; when set, only safe failures retry — transport errors always, 5xx/429 on GETs only, with linear backoff — while other 4xx responses and non-idempotent requests surface immediately.

## Errors

Everything this SDK throws implements `Exceptions\GizmoException`:

| Exception | When |
| --- | --- |
| `Exceptions\RequestException` | 4xx/5xx response (extends Illuminate's) |
| `Exceptions\ConnectionException` | transport failure (extends Illuminate's) |
| `Exceptions\UnexpectedResponseException` | 2xx body that defies the spec — message includes the wire path |

## Testing

```php
use Gizmo\Sdk\Testing\GizmoFake;

Http::fake([GizmoFake::<OPERATION> => Http::response(GizmoFake::<factory>([...overrides]))]);
```

Factories return wire-keyed arrays seeded from spec examples; `<factory>Dto()` variants return hydrated DTOs. Or bypass HTTP entirely with `GizmoClient::fromPendingRequest()`.

> **Fake patterns are method-blind.** `Http::fake()` matches URLs only, so operations on the same path (list vs create) share an identical pattern constant — as array keys, one silently overwrites the other — and a wildcard like `*/things/*` also matches deeper routes. Register more specific patterns first, and branch on `$request->method()` in a fake closure when two operations share a URL.

## Endpoints

### Assemblies — `$client->assemblies()`

| Method | Endpoint | Returns |
| --- | --- | --- |
| `listAssemblies()` | GET `/assemblies` | `Collection<Dto\Assembly>` |

### Widgets — `$client->widgets()`

| Method | Endpoint | Returns |
| --- | --- | --- |
| `searchWidgets(…)` | GET `/widgets/search` | `Collection<Dto\Widget>` |
| `searchWidgetsLazy(...)` | auto-paging | `LazyCollection<Dto\Widget>` |
| `getWidget($sn)` | GET `/widgets/{sn}` | `Dto\Widget` |
| `updateWidget($sn, $body)` | PATCH `/widgets/{sn}` | `Dto\Widget` |
| `listWidgetAlerts($sn)` | GET `/widgets/{sn}/alerts` | `Collection<Dto\WidgetAlert>` |
| `pingWidget($sn)` | POST `/widgets/{sn}/ping` | `mixed` |

