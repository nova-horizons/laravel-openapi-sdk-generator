<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator;

/**
 * The SDK manifests a consumer commits in its composer.json "extra" block,
 * keyed by each producer's API id — one consumer can hold SDKs for several APIs:
 *
 *   "extra": {
 *       "sdk-generator": {
 *           "orbit": {
 *               "namespace": "App\\Sdk\\Orbit",
 *               "out": "app/Sdk/Orbit"
 *           }
 *       }
 *   }
 *
 * The producer's sdk:generate command only knows its own id (config
 * 'sdk-generator.id') and *where* a consumer checkout lives (a per-machine
 * path in the producer's .env); everything that defines the SDK — namespace,
 * output path, client class, allow rules — lives here, committed in the repo
 * that owns the generated code. composer.json is used because it already
 * exists in every consumer and is inert data: the producer parses it
 * cross-repo without executing consumer PHP or leaking its own env.
 */
final readonly class ConsumerManifest
{
    public const EXTRA_KEY = 'sdk-generator';

    private const KNOWN_KEYS = ['namespace', 'out', 'client', 'config-key', 'allow', 'format'];

    private const EXAMPLE = '"extra": {"sdk-generator": {"%s": {"namespace": "App\\\\Sdk\\\\Orbit", "out": "app/Sdk/Orbit"}}}';

    /**
     * @param  list<string>  $allow
     * @param  string|false|null  $format  shell command run from the consumer root
     *                                     after generation, `{out}` replaced with the
     *                                     output path (e.g. "vendor/bin/php-cs-fixer
     *                                     fix {out}", "vendor/bin/sail bin pint {out}").
     *                                     null = default (consumer's Pint when present),
     *                                     false = no format step.
     */
    public function __construct(
        public string $namespace,
        public string $out,
        public ?string $client = null,
        public ?string $configKey = null,
        public array $allow = [],
        public string|false|null $format = null,
    ) {}

    /** @param string $apiId the producer's id — the key this SDK's entry sits under */
    public static function fromCheckout(string $checkoutDir, string $apiId): self
    {
        $path = rtrim($checkoutDir, '/').'/composer.json';
        $example = sprintf(self::EXAMPLE, $apiId);

        if (! is_file($path)) {
            throw new \RuntimeException("No composer.json found in {$checkoutDir} — is this a consumer checkout?");
        }

        try {
            $composer = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("{$path} is not valid JSON: {$e->getMessage()}");
        }

        $extra = is_array($composer) ? ($composer['extra'] ?? null) : null;
        $block = is_array($extra) ? ($extra[self::EXTRA_KEY] ?? null) : null;

        if ($block === null) {
            throw new \RuntimeException(
                "{$path} has no \"extra.".self::EXTRA_KEY."\" block. Commit one in the consumer, e.g.:\n    ".$example
            );
        }
        if (! is_array($block)) {
            throw new \RuntimeException("{$path}: \"extra.".self::EXTRA_KEY.'" must be a JSON object keyed by API id');
        }
        if (isset($block['namespace'])) {
            throw new \RuntimeException(
                "{$path}: \"extra.".self::EXTRA_KEY."\" entries must be keyed by API id — nest your SDK config, e.g.:\n    ".$example
            );
        }

        $data = $block[$apiId] ?? null;
        if ($data === null) {
            $defined = implode(', ', array_map(strval(...), array_keys($block))) ?: '(none)';
            throw new \RuntimeException(
                "{$path}: no \"extra.".self::EXTRA_KEY.".{$apiId}\" entry (defined: {$defined}). Add one, e.g.:\n    ".$example
            );
        }
        if (! is_array($data)) {
            throw new \RuntimeException("{$path}: \"extra.".self::EXTRA_KEY.".{$apiId}\" must be a JSON object");
        }

        $unknown = array_diff(array_map(strval(...), array_keys($data)), self::KNOWN_KEYS);
        if ($unknown !== []) {
            throw new \RuntimeException(
                "{$path}: unknown \"extra.".self::EXTRA_KEY.".{$apiId}\" key(s) ".implode(', ', $unknown).'. Allowed: '.implode(', ', self::KNOWN_KEYS)
            );
        }

        $namespace = $data['namespace'] ?? null;
        if (! is_string($namespace) || $namespace === '') {
            throw new \RuntimeException("{$path}: \"extra.".self::EXTRA_KEY.".{$apiId}.namespace\" is required, e.g. \"App\\\\Sdk\\\\Orbit\"");
        }

        $out = $data['out'] ?? null;
        if (! is_string($out) || $out === '') {
            throw new \RuntimeException("{$path}: \"extra.".self::EXTRA_KEY.".{$apiId}.out\" is required, e.g. \"app/Sdk/Orbit\"");
        }
        if (str_starts_with($out, '/') || str_contains($out, '..')) {
            throw new \RuntimeException("{$path}: \"out\" must be a path relative to the consumer root, without \"..\"");
        }

        return new self(
            namespace: $namespace,
            out: rtrim($out, '/'),
            client: self::optionalString($data, 'client', $path),
            configKey: self::optionalString($data, 'config-key', $path),
            allow: self::allowRules($data, $path),
            format: self::formatValue($data, $path),
        );
    }

    /** @param array<array-key, mixed> $data */
    private static function optionalString(array $data, string $key, string $path): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && (! is_string($value) || $value === '')) {
            throw new \RuntimeException("{$path}: \"{$key}\" must be a non-empty string");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private static function allowRules(array $data, string $path): array
    {
        $allow = $data['allow'] ?? [];
        if (! is_array($allow)) {
            throw new \RuntimeException("{$path}: \"allow\" must be an array of rule names");
        }

        $rules = [];
        foreach ($allow as $rule) {
            if (! is_string($rule) || ! in_array($rule, SpecViolation::RULES, true)) {
                throw new \RuntimeException(
                    "{$path}: unknown allow rule ".var_export($rule, true).'. Known rules: '.implode(', ', SpecViolation::RULES)
                );
            }
            $rules[] = $rule;
        }

        return $rules;
    }

    /** @param array<array-key, mixed> $data */
    private static function formatValue(array $data, string $path): string|false|null
    {
        $format = $data['format'] ?? null;
        if ($format === null || $format === false) {
            return $format;
        }

        if (! is_string($format) || trim($format) === '') {
            throw new \RuntimeException(
                "{$path}: \"format\" must be a shell command string ({out} = output path) or false to disable formatting"
            );
        }

        return $format;
    }
}
