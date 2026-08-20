<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Emitter;

use NovaHorizons\SdkGenerator\Ir\ApiDef;
use NovaHorizons\SdkGenerator\Ir\OperationDef;
use NovaHorizons\SdkGenerator\Ir\ParamDef;
use NovaHorizons\SdkGenerator\Ir\TypeKind;
use NovaHorizons\SdkGenerator\Names;

/**
 * Emits a README.md into the generated SDK so consumers never have to ask
 * how it works — the answer regenerates with the code.
 */
final readonly class ReadmeEmitter
{
    public function __construct(
        private string $namespace,
        private string $clientClass,
        private string $brand,
        private string $configKey,
        private Types $types,
    ) {}

    public function emit(ApiDef $api): string
    {
        $ns = $this->namespace;
        $client = $this->clientClass;
        $fake = $this->brand.'Fake';
        $marker = $this->brand.'Exception';

        $lines = [];
        $lines[] = "# {$api->title} SDK";
        $lines[] = '';
        $lines[] = "Generated Laravel-native client for **{$api->title}** (spec v{$api->version}).";
        $lines[] = 'Do not edit by hand — regenerate from the producer project.';
        $lines[] = '';
        $lines[] = '## Setup';
        $lines[] = '';
        $lines[] = 'Configure and resolve — no service provider or binding needed'
            .' (`#[Singleton]` + `#[Config]` attributes handle both):';
        $lines[] = '';
        $lines[] = '```php';
        $configSegments = explode('.', $this->configKey, 2);
        if (count($configSegments) === 2) {
            [$configFile, $configTail] = $configSegments;
            $env = Names::envPrefix(explode('.', $configTail)[0]);
            $urlComment = $api->serverUrl !== null
                ? "          // optional — defaults to {$api->serverUrl}"
                : '          // required (no server URL in the spec)';
            $lines[] = "// config/{$configFile}.php";
            $lines[] = "'{$configTail}' => [";
            $lines[] = "    'url' => env('{$env}_URL'),{$urlComment}";
            $lines[] = "    'api_key' => env('{$env}_API_KEY'),  // optional; also read: .timeout (seconds), .retries";
            $lines[] = '],';
        } else {
            $lines[] = "// config: {$this->configKey}.url, .api_key, .timeout (seconds), .retries";
        }
        $lines[] = '';
        $lines[] = "\$client = app(\\{$ns}\\{$client}::class);";
        $lines[] = '';
        $lines[] = '// or explicitly:';
        $lines[] = $api->serverUrl !== null
            ? "\$client = \\{$ns}\\{$client}::make(apiKey: '...');  // base URL defaults to {$api->serverUrl}"
            : "\$client = \\{$ns}\\{$client}::make('https://api.example.com', apiKey: '...');";
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'With no URL from config, `make()`, or the spec, the client throws'
            .' `Exceptions\ConfigurationException` on first use — never a silent misdirected request.';
        $lines[] = '';
        $lines[] = '`.timeout` is in seconds (Laravel\'s default 30s when unset). `.retries` is off unless set;'
            .' when set, only safe failures retry — transport errors always, 5xx/429 on GETs only, with'
            .' linear backoff — while other 4xx responses and non-idempotent requests surface immediately.';
        $lines[] = '';
        $lines[] = '## Errors';
        $lines[] = '';
        $lines[] = "Everything this SDK throws implements `Exceptions\\{$marker}`:";
        $lines[] = '';
        $lines[] = '| Exception | When |';
        $lines[] = '| --- | --- |';
        $lines[] = '| `Exceptions\RequestException` | 4xx/5xx response (extends Illuminate\'s'
            .($api->errorClass !== null ? "; `->error()` returns the typed `Dto\\{$api->errorClass}` body)" : ')').' |';
        $lines[] = '| `Exceptions\ConnectionException` | transport failure (extends Illuminate\'s) |';
        $lines[] = '| `Exceptions\UnexpectedResponseException` | 2xx body that defies the spec — message includes the wire path |';
        $lines[] = '';
        $lines[] = '## Testing';
        $lines[] = '';
        $lines[] = '```php';
        $lines[] = "use {$ns}\\Testing\\{$fake};";
        $lines[] = '';
        $lines[] = "Http::fake([{$fake}::<OPERATION> => Http::response({$fake}::<factory>([...overrides]))]);";
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'Factories return wire-keyed arrays seeded from spec examples;'
            ." `<factory>Dto()` variants return hydrated DTOs. Or bypass HTTP entirely with `{$client}::fromPendingRequest()`.";
        $lines[] = '';
        $lines[] = '> **Fake patterns are method-blind.** `Http::fake()` matches URLs only, so operations'
            .' on the same path (list vs create) share an identical pattern constant — as array keys, one'
            .' silently overwrites the other — and a wildcard like `*/things/*` also matches deeper routes.'
            .' Register more specific patterns first, and branch on `$request->method()` in a fake closure'
            .' when two operations share a URL.';
        $lines[] = '';
        $lines[] = '## Endpoints';

        foreach ($api->resources as $resourceName => $operations) {
            $lines[] = '';
            $lines[] = "### {$resourceName} — `\$client->".Names::accessor($resourceName).'()`';
            $lines[] = '';
            $lines[] = '| Method | Endpoint | Returns |';
            $lines[] = '| --- | --- | --- |';
            foreach ($operations as $op) {
                $returns = $this->returnDoc($op);
                $deprecated = $op->deprecated ? ' **(deprecated)**' : '';
                $lines[] = "| `{$op->methodName}({$this->paramSummary($op)})`{$deprecated} | "
                    .strtoupper($op->httpMethod)." `{$op->path}` | {$returns} |";
                if ($op->isPaginated()) {
                    $lines[] = "| `{$op->methodName}Lazy(...)` | auto-paging | `LazyCollection<".$this->itemShort($op).'>` |';
                }
            }
        }

        $lines[] = '';

        return implode("\n", $lines)."\n";
    }

    private function paramSummary(OperationDef $op): string
    {
        $parts = [];
        foreach ($op->params as $param) {
            if ($param->in === 'path' || $param->required) {
                $parts[] = '$'.$param->phpName;
            }
        }
        if ($op->bodyType !== null) {
            $parts[] = '$body';
        }
        $optional = count($op->params) - count(array_filter($op->params, fn (ParamDef $p): bool => $p->in === 'path' || $p->required));
        if ($optional > 0) {
            $parts[] = '…';
        }

        return implode(', ', $parts);
    }

    private function returnDoc(OperationDef $op): string
    {
        $type = $op->returnType;
        if ($type === null) {
            return 'void';
        }

        return match ($type->kind) {
            TypeKind::ArrayOf => '`Collection<'.$this->itemShort($op).'>`',
            TypeKind::Object => '`Dto\\'.$type->className.'`',
            TypeKind::Map => '`array`',
            TypeKind::Mixed => '`mixed`',
            default => '`'.$this->types->native($type).'`',
        };
    }

    private function itemShort(OperationDef $op): string
    {
        $items = $op->returnType?->items;

        return $items?->className !== null ? 'Dto\\'.$items->className : 'mixed';
    }
}
