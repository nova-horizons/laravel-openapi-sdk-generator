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

