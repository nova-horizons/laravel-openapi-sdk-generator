# laravel-openapi-sdk-generator

[![CI](https://github.com/nova-horizons/laravel-openapi-sdk-generator/actions/workflows/ci.yml/badge.svg)](https://github.com/nova-horizons/laravel-openapi-sdk-generator/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/nova-horizons/laravel-openapi-sdk-generator)](https://packagist.org/packages/nova-horizons/laravel-openapi-sdk-generator)

> [!WARNING]
> **Beta.** This package is under active development ahead of a 1.0 release — the generator CLI, config format, and shape of generated code may change between releases. Feedback and issues are welcome at [nova-horizons/laravel-openapi-sdk-generator](https://github.com/nova-horizons/laravel-openapi-sdk-generator/issues).

Generates Laravel-native API clients from OpenAPI 3.0/3.1 specs: `Http` client under the hood, `Illuminate\Support\Collection` returns, `final readonly` DTOs with native backed enums, `Illuminate\Support\Carbon` dates, and zero runtime dependencies beyond what every Laravel app already ships.

Generated output passes **Pint** and **PHPStan level 9 and 10 (max)** out of the box. A generated `Cast` support class narrows `mixed` wire values with runtime checks — no blind casts — and throws `UnexpectedValueException` when a response doesn't match the spec. Every resource method carries `@throws` tags for `RequestException` and `ConnectionException`.

## How it works

```
spec.json → SpecLoader (cebe/php-openapi, refs preserved) → Mapper → IR → Emitters (nette/php-generator) → your formatter
```

The intermediate representation (`src/Ir`) keeps spec parsing and code emission fully decoupled — naming rules, `allOf` flattening, and inline-schema hoisting all happen in the Mapper and are testable without generating a single file. Generated code is committed and reviewed like any other diff; see `examples/` for two complete generated SDKs (from the specs in `tests/fixtures/`).

## Setup & generation

Two ways to run the generator, depending on which side of the API you sit on.

### From the producer API (push model)

For teams that own both the API and its Laravel consumers: install in the **producer**, and one command refreshes the SDK in every consumer checkout — regenerating the spec first, so it can never be stale.

```bash
composer require --dev nova-horizons/laravel-openapi-sdk-generator
php artisan vendor:publish --tag=sdk-generator-config
```

Each consumer declares its SDKs in its own `composer.json` — the repo that owns the generated code owns its configuration. Entries are keyed by each producer's API id, so one consumer can hold SDKs for several APIs:

```json
"extra": {
    "sdk-generator": {
        "orbit": {
            "namespace": "App\\Sdk\\Orbit",
            "out": "app/Sdk/Orbit",
            "client": "OrbitClient",
            "allow": ["missing-error-schema"],
            "format": "vendor/bin/sail php vendor/bin/php-cs-fixer fix {out}"
        }
    }
}
```

`namespace` and `out` (relative to the consumer root) are required. `format` is any shell command, run from the consumer's root with `{out}` substituted — php-cs-fixer, a Sail-wrapped invocation, whatever that project uses; when omitted, the consumer's `vendor/bin/pint` runs if present, and `"format": false` disables the step.

The producer declares its id and *where* consumer checkouts live; machine-specific paths stay out of version control — `config/sdk-generator.php` reads them from `.env`:

```php
'id' => 'orbit',   // the key consumers use in extra.sdk-generator

'consumers' => [
    'space-app' => env('SDK_CONSUMER_SPACE_APP'),  // .env: SDK_CONSUMER_SPACE_APP=../space-app
],
```

Then:

```bash
php artisan sdk:generate space-app  # one consumer
php artisan sdk:generate --all    # every consumer
```

The command runs the `pregenerate` hook first (default `l5-swagger:generate`, so the spec is always fresh), generates into each consumer's `out` path, formats with the consumer's own toolchain, and warns if the consumer's config file doesn't seem to define the `services.{brand}` entry the generated client reads.

### Standalone, from a spec file (pull model)

Anywhere you have a spec — including inside a consumer that pulls a published spec itself:

```bash
vendor/bin/sdk-generate storage/api-docs/api-docs.json \
    --namespace='App\\Sdk\\Orbit' \
    --out=app/Sdk/Orbit \
    --client=OrbitClient
```

Optional flags: `--config-key=services.orbit` (where the client reads its URL/key from) and `--allow=missing-error-schema,untyped-response` (tolerate specific spec violations — see below).

## Using the generated SDK

```php
$client = app(GizmoClient::class);   // configured from services.gizmo.url / .api_key

/** @var Collection<int, Widget> $widgets */
$widgets = $client->widgets()->searchWidgets(q: 'coupler', limit: 25);

$widgets->first()->recWidgetSN;   // int — hydrated from __rec_WidgetSN
```

### Client & container

- `#[Singleton]` + `#[Config]` attributes on the client: `app(GizmoClient::class)` resolves configured and shared (from `services.{brand}.url` / `.api_key`, override with `--config-key`) with no service provider. Requires Laravel ≥ 12.31.
- The client reads four config keys: `services.{brand}.url`, `.api_key`, `.timeout` (seconds; Laravel's default 30s when unset) and `.retries` (retries are off when unset). When `retries` is set, only safe failures re-fire — transport errors always, 5xx/429 on GETs only, with linear backoff; other 4xx responses and non-idempotent requests surface immediately.
- Base URL resolution is **config first, spec second, never silent**: `services.{brand}.url` wins; absent that, the spec's `servers[0]` is the default when it's trustworthy (absolute, non-localhost — a localhost/relative entry is rejected with a generation warning, since that's usually just the host that generated the spec); with no URL from either, the client throws `Exceptions\ConfigurationException` on first use with the exact config key to set.
- `make()` and `fromPendingRequest()` cover manual construction (custom middleware, tests); `make()`'s base URL defaults to the spec's server URL when one was baked in.
- Escape hatch: `$client->http()` exposes the underlying `PendingRequest` for anything the spec doesn't cover.

### Collections & lazy pagination

Endpoints returning DTO lists return `Illuminate\Support\Collection`. Offset/limit list endpoints also get a `{method}Lazy()` sibling returning a `LazyCollection` that auto-pages during iteration.

### Exceptions

Every SDK gets its own hierarchy under `Exceptions\` — `catch (OrbitException $e)` catches anything the SDK throws. `RequestException`/`ConnectionException` extend Illuminate's, so existing catch sites keep working. When the spec documents error bodies with a single schema, `RequestException::error()` returns the typed payload. Responses that defy the spec throw `UnexpectedResponseException` with the exact wire path (`Beacon.fixes[]: expected array, got string`).

### Partial updates (the `Omitted` sentinel)

Request-only DTOs use `Omitted::Value` as the default so PATCH semantics survive:

```php
new WidgetUpdate(widgetName: 'Torsion Coupler');  // sends {"widget_name": "Torsion Coupler"}
new WidgetUpdate(binLocation: null);              // sends {"bin_location": null} — clears the field
new WidgetUpdate();                               // sends {} — touches nothing
```

This matches Laravel servers that call `$request->validated()` and update with the result: omitted keys are untouched, explicit nulls are written.

### Testing fakes

`Testing\{Brand}Fake` ships wire-array factories per DTO (seeded from spec `example`s) and an `Http::fake` URL pattern constant per operation — `Http::fake()` works exactly as in first-party code, because resources call through `PendingRequest`:

```php
Http::fake([OrbitFake::GET_BEACON => Http::response(OrbitFake::beacon(['active' => false]))]);
```

### Naming

Wire names are preserved in transport; PHP names strip leading underscores and camelCase on `_` boundaries, keeping embedded capitalisation — which keeps them readable for APIs that leak legacy database column conventions into the wire format: `__rec_SerialNumber` → `$recSerialNumber`, `calc_TotalDueUSD` → `$calcTotalDueUSD`. Collisions are a generation-time error.

### Auth

If the spec declares an `apiKey`/`bearer` security scheme it is honoured; otherwise `Client::make(..., apiKey:)` sends the configured `defaultApiKeyHeader` (`X-Api-Key`). Document the scheme in L5-Swagger with:

```php
#[OA\SecurityScheme(securityScheme: 'appApiKey', type: 'apiKey', in: 'header', name: 'X-Api-Key')]
```

## Spec support & limits

Handled: `$ref` (schemas/parameters/requestBodies/responses), `allOf` (flattened, not inheritance), inline schema hoisting with structural dedupe (`title` wins when present), `nullable`, `additionalProperties` maps, property enums → native backed enums, `format: date`/`date-time` → `Illuminate\Support\Carbon`, path+query parameters, JSON request/response bodies.

Degraded deliberately: `oneOf`/`anyOf` → `mixed` (with a generation warning), inline query-param enums → documented strings, non-JSON bodies → skipped with a warning (use `$client->http`).

Strict by default: generation fails on spec-quality problems that degrade the SDK (undocumented error bodies, untyped `{}` responses, missing operationIds, …), each violation naming the fix. `--allow=<rule>` (or `"allow"` in the consumer manifest) downgrades specific rules to visible warnings.

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
