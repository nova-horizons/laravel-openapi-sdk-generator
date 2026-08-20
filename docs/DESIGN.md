# Design and Decisions

This document records how `nova-horizons/laravel-openapi-sdk-generator` came to be, the architecture it landed on, and the reasoning behind every decision that wasn't obvious. The README covers *how to use it*; this covers *why it is the way it is*. Written August 2026, at the point where the generator was feature-complete and its first consumer was fully migrated. (Client-identifying details were removed before publishing; the "Gizmo" and "Orbit" specs in `tests/fixtures/` are synthetic stand-ins that exercise the same feature surface as the real specs the generator was built against.)

## Origin

The agency runs internal Laravel APIs documented with L5-Swagger, consumed by sibling Laravel apps. The consumers were using vendored output from the classic `openapi-generator` `php` generator: Guzzle-based, getter/setter models, `ObjectSerializer` reflection, failing PHPStan, and stylistically alien to a Laravel codebase.

The alternatives were surveyed before building anything. `php-nextgen` (openapi-generator's successor) was still beta and still Guzzle + hand-rolled serialization. Jane produces genuinely good PSR-18 code but is opinionated and un-Laravel. The Saloon SDK Generator was the closest fit philosophically (PHP-native, `nette/php-generator`, Laravel-flavored) but scaffolds rather than finishes, and pulls Saloon as a runtime dependency. The requirements that none of them met simultaneously: Laravel's `Http` client, `Collection` returns, native readonly DTOs with **zero runtime dependencies beyond what Laravel ships**, and output clean enough to live inside a consumer's own static analysis rather than being excluded as vendor code.

A survey of the two real specs settled the scope question. Neither used `oneOf`/`anyOf`, discriminators, multipart, or XML — the long tail that makes generic generators hard. That reduced the estimate from "weeks to months" to about two weeks, and the build came in well under that. The lesson generalizes: **a generator for your own specs is dramatically cheaper than a generator for all specs**, and strictness (see below) keeps it that way by pushing complexity back into the specs rather than absorbing it.

## Architecture

```
spec.json ─→ SpecLoader ─→ Mapper ─→ IR ─→ Emitters ─→ PsrPrinter ─→ files ─→ consumer's formatter
             (cebe,        (validate,  (plain          (nette/
              refs kept)    flatten,    PHP objects)    php-generator)
                            hoist,
                            name)
```

**SpecLoader** (`src/SpecLoader.php`) reads via `devizzent/cebe-php-openapi` (the maintained fork; the original stalled on OpenAPI 3.1) but deliberately does **not** resolve `$ref`s. Resolution destroys the ref names, and ref names are exactly what drive DTO class names. The Mapper resolves pointers manually on demand.

**Mapper** (`src/Mapper.php`) walks the raw document (cebe has already validated it) and produces the intermediate representation. All judgment lives here: `allOf` flattening (merge, never inheritance), inline-schema hoisting with structural dedupe (`title` wins, else `{OperationId}Response`, identical untitled shapes share one class), the legacy-DB-friendly naming rule (`__rec_SerialNumber` → `recSerialNumber`: strip leading underscores, camelCase on `_`, preserve embedded caps, collisions are generation-time errors), request/response usage classification for sentinel detection, error-schema detection, and strict validation (below).

**IR** (`src/Ir/`) is a handful of plain readonly classes: `TypeRef` (a `TypeKind` enum plus className/items/nullable), `PropertyDef`, `ObjectDef`, `EnumDef`, `ParamDef`, `OperationDef`, `ApiDef`. The IR is the load-bearing decision of the whole design: spec parsing and code emission never touch each other, the Mapper is testable without generating a file, and every naming/typing decision happens exactly once instead of being smeared through templates. This is also why `nette/php-generator` beat mustache templates — the emitters build code structurally and the printer handles namespaces and imports.

**Emitters** (`src/Emitter/`) each own one output shape: `DtoEmitter`, `EnumEmitter`, `ResourceEmitter` (+ `ResourceBaseEmitter` for the shared base class), `ClientEmitter`, `ExceptionsEmitter`, `CastEmitter`, `FakeEmitter`, `OmittedEmitter`, `ReadmeEmitter`. `Types` translates `TypeRef` to native/doc type strings; `Expressions` builds the hydration/serialization expressions and threads wire paths through every cast.

**Formatting is not the generator's job.** Output goes through `PsrPrinter`, then the consumer's own formatter (their Pint or php-cs-fixer config). Chasing byte-perfect formatting from the emitter was rejected early; the pipeline runs the consumer's tool instead, so generated code always matches house style.

**Distribution model.** The generator is a `require-dev` of each *producer* API project. `php artisan sdk:generate <consumer>` runs the l5-swagger pregenerate hook, generates into the consumer's checkout (path + namespace + config key per consumer in `config/sdk-generator.php`), and formats with the consumer's formatter. Generated code is committed in the consumer and reviewed like any diff. No per-API package publishing; the spec lives with the producer and can never drift from it silently. Every generated file is stamped with the package version, spec title/version, and a 12-char spec content hash — cross-repo drift is at least *inspectable* (a `--check` CI mode was considered and dropped: with producers and consumers in separate repos there's no single place to diff).

## The decisions and why

**Native readonly DTOs, not spatie/laravel-data.** laravel-data's value is runtime reflection so you don't write boilerplate — but a generator writes the boilerplate anyway, so you'd pay reflection cost at runtime for a problem already solved at build time (roughly an order of magnitude, worse with nesting). It would also make every consumer inherit the dependency and its upgrade treadmill. DTOs are `final readonly class` with promoted constructor properties, `fromArray()`, and `jsonSerialize()`. Enums are native backed enums. One subtlety consumers hit: `jsonSerialize()` intentionally leaves nested DTOs as objects for `json_encode` to recurse, so in-memory wire-array round-trips need a `json_decode(json_encode(...))` pass.

**PHPStan level 10 (max) via the generated `Cast` class.** The first iteration targeted level 6 by letting `mixed` flow freely (legal below level 9). Raising the bar meant no blind casts of `mixed`, so every wire value routes through a per-SDK `Cast` support class (`toInt`, `toStringOrNull`, `toDate`, `toArray`, `toList`, `toEnum`, typed maps…) that narrows with runtime `is_*` checks. Scalars still coerce (`"42"` → `42` — legacy database bridges often send numeric strings), everything else throws. Two side effects turned out to matter more than the level number: responses that defy the spec now fail *loudly* instead of silently coercing garbage, and every `Cast` call carries its wire path, so failures read `Beacon.fixes[]: expected array, got string`. Two PHPStan-shaped subtleties worth remembering: `(array) $mixed` produces a *concretely wrong* type (`array<mixed,mixed>`) that fails argument checks even at level 6, whereas passing `mixed` directly is fine below 9 — the level-6 design exploited that, the level-10 design replaced it with `Cast`; and `fromArray` takes `@param array<array-key, mixed>` (not `array<string, mixed>`) so narrowed plain arrays are accepted.

**The `Omitted` sentinel for partial updates.** Reading the first consumer's server code settled a design question the spec couldn't: its update controllers do `$request->validated()` → `update($data)` with all-`nullable` rules, meaning *omitted field = untouched, explicit null = written to the DB*. A client serializing all-nulls-as-omitted could never clear a field; one sending nulls for unset fields would wipe data. So request-only all-optional DTOs get union-typed properties (`Omitted|string|null $binLocation = Omitted::Value`) and serialize only what was explicitly provided. `new WidgetUpdate(binLocation: null)` clears; `new WidgetUpdate()` sends `{}`. Sentinel style applies to any request-only schema (creates included — behaviorally equivalent there, and it keeps the rule simple).

**`Illuminate\Support\Carbon`, not `CarbonImmutable`.** Chosen deliberately for ecosystem consistency at the cost of immutability — illuminate ships no immutable variant, so dates inside `readonly` DTOs are mutable objects (readonly protects the reference, not the Carbon instance). If that ever bites, the revert is one line in `Types.php`.

**Exception hierarchy: extend Illuminate's, add a marker.** Laravel's client throws its own classes, so the generated base `Resource::send()` catches and re-wraps into per-SDK `Exceptions\RequestException`/`ConnectionException` — which **extend** Illuminate's, so pre-existing catch sites keep working and the migration is non-breaking. Everything (including `UnexpectedResponseException` from `Cast`) implements a `{Brand}Exception` marker interface: `catch (OrbitException $e)` catches anything the SDK throws, and multiple SDKs in one app are distinguishable by type. When the spec documents error bodies with a single schema, `RequestException::error(): ?Error` returns the typed payload — which replaced hand-rolled JSON parsing of error bodies in the first consumer. `@throws` tags are precise: `UnexpectedResponseException` only on hydrating (non-void) methods, because declaring it on void operations is provably too wide. One migration hazard worth remembering: tests that simulate SDK failures by throwing Illuminate's parent classes sail straight past catch sites that catch the SDK subclass — migrate the test fakes with the catch sites.

**Container attributes, not a service provider.** The client carries `#[Singleton]` on the class and `#[Config('{key}.url')]`-style attributes on constructor params (url, api_key, timeout, retries). `app(OrbitClient::class)` resolves configured and shared with zero registration. The `PendingRequest` builds lazily on first `http()` call so `fromPendingRequest()` (tests, custom middleware) never touches config. This requires Laravel ≥ 12.31 for `#[Singleton]`; the alternative (generated ServiceProvider) was considered and rejected as ceremony.

**Strict spec validation, leniency opt-in.** Generation *fails* on spec-quality problems that degrade the SDK: undocumented error bodies, untyped `{}` responses, multi-variant `oneOf`/`anyOf` (single-variant is unwrapped — it's swagger-php's idiom for a nullable `$ref`, not a real union), non-JSON bodies, header/cookie params, missing operationIds. Each violation names the fix. `--allow=<rule>` (CLI) or `'allow' => [...]` (per consumer) downgrades specific rules to warnings — visibly, on every run. This flipped the usual generator posture on purpose: instead of a lenient generator absorbing spec debt forever, the generator applies pressure and the specs improve. It worked: the first producer's spec was brought fully strict-clean (an `Error` schema on every error response, typed responses throughout) within a day of the rule landing, and the typed `error()` accessor appeared as a direct consequence.

**Auth is config, not spec-driven.** The original specs are for internal, network-restricted APIs and declare no security schemes. The client supports a simple api-key header (spec `apiKey`-in-header or `http bearer` when declared; configurable default header otherwise) and nothing more. OAuth flows are explicitly out of scope.

**Path parameters stay plain method parameters.** Lifting a recurring path parameter into client config was considered and dropped after reading the first consumer's wrapper — it already threads that value through every call, so the wrapper remains the place that owns it.

**Degradations that are deliberate:** multi-variant `oneOf`/`anyOf` → `mixed` (behind a violation), inline query-param enums → documented strings, map value types beyond scalars → `array<string, mixed>` docs (matching what hydration actually produces), non-JSON bodies → skipped (escape hatch: `$client->http()`).

## DX features and their shapes

- **Lazy pagination**: any GET returning a DTO list with int `offset`+`limit` query params gets a `{method}Lazy(...)` sibling returning `LazyCollection<int, Dto>` that auto-pages during iteration (default chunk 500). Every paginated endpoint in the original producer specs qualified.
- **Generated fakes** (`Testing\{Brand}Fake`): a wire-array factory per response DTO with defaults computed *at generation time* (spec `example` values first, then type-based placeholders, nested DTOs expanded, cycles guarded), a hydrated `{name}Dto()` variant, and an `Http::fake` URL-pattern constant per operation. `Http::fake([OrbitFake::GET_BEACON => Http::response(OrbitFake::beacon(['active' => false]))])` is a complete test setup.
- **`deprecated: true` flow-through**: operations and properties marked deprecated in the spec emit `@deprecated` tags — consumers running phpstan-deprecation-rules get CI failures for endpoints being sunset. This makes spec deprecation an enforceable lifecycle tool rather than documentation.
- **Per-SDK generated README**: setup, exception table, testing recipes, endpoint table with deprecation markers and lazy variants. Regenerates with the code, so it can't rot.
- Considered and *not* built: column-map constants for a producer's column-selection param (too narrow), Saloon-style record/replay fixtures (fakes cover most of it), Http-client event listeners (Laravel already fires them), a PHPStan plugin policing sentinel misuse (wait for it to hurt).

## Operational lessons (read before debugging "generator bugs")

- **A stale vendor tree produces convincing false errors.** A checkout whose `composer.lock` disagrees with `vendor/composer/installed.json` can make framework features look broken and crash static analysis at boot. When full-repo analysis crashes at boot, check those two against each other *first*.
- **Analysis must run on the runtime PHP.** Consumers run their quality suites inside their containers (pinned PHP version). Static-binary approximations on other 8.x versions produced version-specific phantoms (`Pdo\Mysql` exists only on 8.4+, etc.).
- **PHPStan's exception rules drive the `@throws` architecture.** Consumers typically check all exceptions except a small unchecked list (`RuntimeException`, `LogicException`, …). That's why `UnexpectedResponseException` extends `UnexpectedValueException` (< `RuntimeException` — unchecked, no cascade) while `Request`/`ConnectionException` (< `Exception` via `HttpClientException` — checked) cascade `@throws` annotations through every caller, and why `Cast::fail()` must itself declare `@throws` or every catch of it reads as dead.

## Verification contract

Three layers, all of which gate changes to the generator:

1. **Generator's own code**: PHPStan level 8, Pint, PHPUnit golden tests against the synthetic specs in `tests/fixtures/` (`gizmo-api.json` exercises the messy-legacy-API path incl. `--allow`, `orbit-api.json` the strict-clean path, `errdemo-spec.json` error schemas and deprecation).
2. **Generated corpus**: regenerate `examples/`, must pass Pint `--test` and PHPStan **level 10** standalone.
3. **Runtime smoke suite** (30+ checks, run against the original consumers): hydration through faked HTTP, sentinel semantics all three ways, exception hierarchy and `error()`, lazy pagination request counts, fake factories, container attributes via reflection.

The consumer-side contract is stronger still: generated code passes the *consumer's* full PHPStan config (level 6 + larastan + strict flags + exception checking in the first consumer's case) inside their own analysis paths, not excluded from it.

## State as of this writing

Feature-complete as `nova-horizons/laravel-openapi-sdk-generator`, constraints `illuminate ^12.66–^13`, PHP `^8.3` (Laravel 10/11 and PHP 8.2 support dropped August 2026). First producer spec strict-clean with zero `--allow`; second producer still generating with `--allow=missing-error-schema,untyped-response` until its spec documents error bodies. First consumer fully migrated and verified by its own full static-analysis suite.
