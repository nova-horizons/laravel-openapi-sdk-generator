# laravel-openapi-sdk-generator

[![CI](https://github.com/nova-horizons/laravel-openapi-sdk-generator/actions/workflows/ci.yml/badge.svg)](https://github.com/nova-horizons/laravel-openapi-sdk-generator/actions/workflows/ci.yml)

> [!WARNING]
> **Beta.** This package is under active development ahead of a 1.0 release — the generator CLI, config format, and shape of generated code may change between releases. Feedback and issues are welcome at [nova-horizons/laravel-openapi-sdk-generator](https://github.com/nova-horizons/laravel-openapi-sdk-generator/issues).

Generates Laravel-native API clients from OpenAPI 3.0/3.1 specs: `Http` client under the hood, `Illuminate\Support\Collection` returns, `final readonly` DTOs with native backed enums, `Illuminate\Support\Carbon` dates, and zero runtime dependencies beyond what every Laravel app already ships.

Generated output passes **Pint** and **PHPStan level 9 and 10 (max)** out of the box. A generated `Cast` support class narrows `mixed` wire values with runtime checks — no blind casts — and throws `UnexpectedValueException` when a response doesn't match the spec. Every resource method carries `@throws` tags for `RequestException` and `ConnectionException`.

## How it works

```
spec.json → SpecLoader (cebe/php-openapi, refs preserved) → Mapper → IR → Emitters (nette/php-generator) → Pint
```

The intermediate representation (`src/Ir`) keeps spec parsing and code emission fully decoupled — naming rules, `allOf` flattening, and inline-schema hoisting all happen in the Mapper and are testable without generating a single file.

## Usage

### Standalone

```bash
vendor/bin/sdk-generate storage/api-docs/api-docs.json \
    --namespace='App\\Sdk\\Orbit' \
    --out=../consumer/app/Sdk/Orbit \
    --client=OrbitClient
```

### As an artisan command (in the producer API project)

```bash
composer require --dev nova-horizons/laravel-openapi-sdk-generator
php artisan vendor:publish --tag=sdk-generator-config
```

Configure consumers in `config/sdk-generator.php`, then:

```bash
php artisan sdk:generate billing  # one consumer
php artisan sdk:generate --all    # every consumer
```

The command runs the `pregenerate` hook first (default `l5-swagger:generate`, so the spec is always fresh), generates into each consumer's path, and formats the output with the **consumer's own** `vendor/bin/pint`.

## Generated code

```php
$client = GizmoClient::make('https://api.example.com/v1', apiKey: config('services.gizmo.key'));

/** @var Collection<int, Widget> $widgets */
$widgets = $client->widgets()->searchWidgets(q: 'coupler', limit: 25);

$widgets->first()->recWidgetSN;   // int — hydrated from __rec_WidgetSN
```

- Every SDK gets its own exception hierarchy under `Exceptions\` — `catch (OrbitException $e)` catches anything the SDK throws; `RequestException`/`ConnectionException` extend Illuminate's so existing catch sites keep working. When the spec documents error bodies with a single schema, `RequestException::error()` returns the typed payload.
- `#[Singleton]` + `#[Config]` attributes on the client: `app(OrbitClient::class)` resolves configured (from `services.{brand}.url` / `.api_key`, override with `--config-key`) with no service provider. `make()` and `fromPendingRequest()` cover manual construction.
- Offset/limit list endpoints also get `{method}Lazy()` returning a `LazyCollection` that auto-pages during iteration.
- `Testing\{Brand}Fake` ships wire-array factories per DTO (seeded from spec `example`s) and an `Http::fake` URL pattern constant per operation:

  ```php
  Http::fake([OrbitFake::GET_BEACON => Http::response(OrbitFake::beacon(['active' => false]))]);
  ```
- `Http::fake()` works exactly as in first-party code — resources call through `PendingRequest`.
- Escape hatch: `$client->http()` exposes the underlying `PendingRequest`.

See `examples/` for two complete generated SDKs (from the specs in `tests/fixtures/`).

### Naming

Wire names are preserved in transport; PHP names strip leading underscores and camelCase on `_` boundaries, keeping embedded capitalisation — which keeps them readable for APIs that leak legacy database column conventions into the wire format: `__rec_SerialNumber` → `$recSerialNumber`, `calc_TotalDueUSD` → `$calcTotalDueUSD`. Collisions are a generation-time error.

### Partial updates (the `Omitted` sentinel)

Request-only DTOs use `Omitted::Value` as the default so PATCH semantics survive:

```php
new WidgetUpdate(widgetName: 'Torsion Coupler');  // sends {"widget_name": "Torsion Coupler"}
new WidgetUpdate(binLocation: null);              // sends {"bin_location": null} — clears the field
new WidgetUpdate();                               // sends {} — touches nothing
```

This matches Laravel servers that call `$request->validated()` and update with the result: omitted keys are untouched, explicit nulls are written.

### Auth

If the spec declares an `apiKey`/`bearer` security scheme it is honoured; otherwise `Client::make(..., apiKey:)` sends the configured `defaultApiKeyHeader` (`X-Api-Key`). Document the scheme in L5-Swagger with:

```php
#[OA\SecurityScheme(securityScheme: 'appApiKey', type: 'apiKey', in: 'header', name: 'X-Api-Key')]
```

## Spec support & limits

Handled: `$ref` (schemas/parameters/requestBodies/responses), `allOf` (flattened, not inheritance), inline schema hoisting with structural dedupe (`title` wins when present), `nullable`, `additionalProperties` maps, property enums → native backed enums, `format: date`/`date-time` → `Illuminate\Support\Carbon`, path+query parameters, JSON request/response bodies.

Degraded deliberately: `oneOf`/`anyOf` → `mixed` (with a generation warning), inline query-param enums → documented strings, non-JSON bodies → skipped with a warning (use `$client->http`).

## Development

```bash
composer install
vendor/bin/phpunit          # golden tests against the specs in tests/fixtures
vendor/bin/phpstan          # level 8 on the generator itself
vendor/bin/pint --test
```

The CI contract for changes: regenerate `examples/`, and the output must pass Pint `--test` and PHPStan level 10.

## License

MIT. Generated output belongs to you: code this tool generates from your specs carries no license obligation to this package.
