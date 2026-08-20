# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`nova-horizons/laravel-openapi-sdk-generator` — generates Laravel-native API clients (Http facade, Collections, `final readonly` DTOs, native backed enums) from OpenAPI 3.0/3.1 specs. README.md covers usage; **docs/DESIGN.md records why every non-obvious decision was made — read it before changing behavior or "fixing" something that looks odd.**

This repo is public (github.com/nova-horizons/laravel-openapi-sdk-generator). It was sanitized before publishing: never reintroduce client names, real API specs, real hostnames, or vendor-identifying field conventions. All fixtures and examples are synthetic ("Gizmo", "Orbit", "ErrDemo").

## Commands

```bash
composer install
vendor/bin/phpunit                                    # golden tests against synthetic specs in tests/fixtures/
vendor/bin/phpunit --filter test_generates_gizmo_sdk  # single test
vendor/bin/phpstan                                    # level 8 on src/ (config in phpstan.neon)
vendor/bin/pint --test                                # style check (fix by dropping --test)
```

Run generation standalone:

```bash
php bin/sdk-generate <spec.json> --namespace='Vendor\\Sdk' --out=./generated [--client=FooClient] [--config-key=services.foo] [--allow=rule1,rule2]
```

### Regenerating the examples corpus (required for any generator change)

The CI contract: after changing the generator, regenerate `examples/` and the output must pass Pint `--test` and PHPStan level 10. The committed examples are Pint-formatted, so run Pint after generating:

```bash
php bin/sdk-generate tests/fixtures/gizmo-api.json --namespace='Gizmo\\Sdk' --client=GizmoClient \
    --allow=missing-error-schema,untyped-response --out=examples/gizmo-sdk
php bin/sdk-generate tests/fixtures/orbit-api.json --namespace='Orbit\\Sdk' --out=examples/orbit-sdk
vendor/bin/pint examples/
vendor/bin/pint --test examples/
vendor/bin/phpstan analyse examples --level=max --no-progress --memory-limit=1G
```

(The gizmo spec needs `--allow` — it deliberately exercises the violation path; the orbit spec is strict-clean. A PHPStan "severe errors / result incomplete" failure is usually the 128M default memory limit or a stale cache from a broken vendor tree — re-run with `--memory-limit=1G` before diagnosing further.)

## Architecture

```
spec.json → SpecLoader → Mapper → IR → Emitters → PsrPrinter → files → consumer's formatter
```

The IR (`src/Ir/` — plain readonly classes: `ApiDef`, `ObjectDef`, `OperationDef`, `TypeRef`, …) fully decouples spec parsing from code emission. Every naming/typing decision happens exactly once, in the Mapper, and is testable without generating a file.

- **`SpecLoader`** reads via `devizzent/cebe-php-openapi` and deliberately does **not** resolve `$ref`s — ref names drive DTO class names. The Mapper resolves pointers manually.
- **`Mapper`** holds all judgment: `allOf` flattening (merge, not inheritance), inline-schema hoisting with structural dedupe, the legacy-DB naming rule (`__rec_SerialNumber` → `recSerialNumber`; collisions are generation-time errors), request/response usage classification (drives the `Omitted` sentinel), error-schema detection, and strict validation. It collects `violations` (fail generation unless allowed via `--allow`) and `warnings`.
- **`SpecViolation`** defines the strict-validation rules (`untyped-response`, `missing-error-schema`, `oneof-anyof`, `non-json-body`, `skipped-param`, `missing-operation-id`). Strictness is deliberate policy: the generator pressures specs to improve rather than absorbing spec debt. Don't loosen a rule; leniency is opt-in per rule.
- **Emitters** (`src/Emitter/`) each own one output shape (DTOs, enums, resources, client, exceptions, `Cast`, fakes, `Omitted`, README). `Types` translates `TypeRef` to type strings; `Expressions` builds hydration/serialization expressions and threads wire paths into every `Cast` call (so runtime failures read `Beacon.fixes[]: expected array, got string`).
- **Formatting is not the generator's job** — output goes through `PsrPrinter`, then the consumer's own Pint. Don't chase byte-perfect formatting in emitters.

Two entry points share `Generator`: `bin/sdk-generate` (standalone CLI) and `src/Laravel/GenerateSdkCommand.php` (`php artisan sdk:generate <consumer>` in a producer API project — runs the l5-swagger pregenerate hook, then the consumer's own format toolchain: the manifest's `format` shell command if set, else the consumer's Pint). Consumer targeting is split deliberately: the producer's `config/sdk-generator.php` maps consumer keys to checkout paths via `.env` (never committed paths), and everything defining the SDK (namespace/out/client/allow/format) lives in the consumer's `composer.json` `extra.sdk-generator` block — keyed by the producer's API id (`sdk-generator.id` config), so one consumer can hold SDKs for several APIs — parsed by `src/ConsumerManifest.php` (inert JSON on purpose — never `require` consumer PHP cross-repo).

## Invariants of the generated code

These are contracts consumers depend on — changes to them are breaking:

- Generated output passes **PHPStan level 10** standalone. No blind casts: every wire value routes through the generated `Cast` class, which narrows `mixed` with runtime checks and throws `UnexpectedResponseException` on mismatch (numbers and numeric strings still interconvert when lossless — some backends send numeric strings; lossy or non-numeric values throw).
- Request-only DTOs use the `Omitted::Value` sentinel default so PATCH semantics survive: omitted ≠ null (see README "Partial updates").
- Per-SDK exceptions **extend** Illuminate's (`RequestException`/`ConnectionException`) and implement a `{Brand}Exception` marker interface. `UnexpectedResponseException` extends `UnexpectedValueException` deliberately — it must stay PHPStan-unchecked while Request/Connection cascade `@throws` (see docs/DESIGN.md "Operational lessons").
- The client uses `#[Singleton]` + `#[Config]` container attributes (needs Laravel ≥ 12.31), no service provider. Base URL resolves config → trustworthy spec `servers[0]` default (absolute, non-localhost; else a generation warning) → generated `ConfigurationException` on first use. Never a silent `''`.
- Zero runtime dependencies beyond what Laravel ships.
- Every generated file is stamped with spec title/version and a 12-char spec hash.

## Notes

- Fixtures: `tests/fixtures/gizmo-api.json` (messy legacy-DB API: naming rule, allOf, Omitted, lazy pagination, deliberate violations), `tests/fixtures/orbit-api.json` (strict-clean: derived client name, enums, inline hoisting, `additionalProperties` map, typed `error()`), `tests/fixtures/errdemo-spec.json` (error schemas + deprecation). `examples/` is generated from the first two.
- `.gitattributes` export-ignores tests/docs/examples/dev-config from composer dist archives; keep it in sync when adding top-level dev files.
- CI (`.github/workflows/ci.yml`): a PHP 8.3–8.5 × Laravel 12/13 matrix runs PHPUnit, then generates fresh SDKs from the fixtures and runs PHPStan level max on that output per leg (proves generation works and output analyzes clean on every supported combo); a `quality` job runs PHPStan/Pint on the generator; a `corpus` job regenerates `examples/` and fails on drift from the committed output (committed examples exist for reviewable diffs and showcase, not as the thing CI trusts). Dependabot runs weekly (Friday noon Central) for composer + actions.
- If tooling fails at boot with confusing errors, check the vendor tree first (`composer.lock` vs `vendor/composer/installed.json`) — a stale/broken vendor tree produces convincing false errors.
