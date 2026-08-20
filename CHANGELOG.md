# Changelog

## Unreleased

### Changed — breaking for generated clients

- **Retries no longer re-fire unsafe requests.** The generated client's `retry()` now passes a `when` callback: transport failures (`ConnectionException`) always retry; 5xx/429 retry only on GET; other 4xx responses and non-idempotent methods surface immediately. Previously *every* failed response retried — a POST returning 422 was re-fired `retries` more times before the consumer saw the validation error. The retry delay now backs off linearly (100ms × attempt) instead of a fixed 100ms.

### Changed — generated `Cast` hardening (only affects payloads that were silently corrupted before)

- `toInt`/`toFloat` coerce only numeric strings, and `toInt` only integral ones: `"abc"` used to return `0` and `"12.7"` silently truncated to `12` — both now throw the path'd `UnexpectedResponseException`. Lossless conversions (`"42"`, `"42.0"`, float `42.0`) still coerce.
- `toBool` accepts only `0`/`1`/`'0'`/`'1'`/`'true'`/`'false'`: `"false"` used to coerce to `true`.
- `toString` no longer stringifies booleans (`false` used to become `''`).
- `toDate` rejects `''` and `0000-00-00` zero-dates: Carbon silently parses those as *now* and year −1.
- `toEnum` compares stringified backing values, so an int-backed enum receiving `"3"` (or a string-backed one receiving `3`) hydrates correctly instead of throwing an unpathed `TypeError`.
- Typed-map cast failures now name the failing key (`UsageReport.counts[beacon-7]: expected int, got string`).

### Added

- The generated README and `{Brand}Fake` class docblock now warn that `Http::fake()` URL patterns are method-blind: same-path operations share an identical pattern constant (as array keys, one silently overwrites the other) and wildcards match deeper routes.
