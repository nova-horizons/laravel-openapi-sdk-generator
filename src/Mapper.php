<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator;

use Illuminate\Support\Str;
use NovaHorizons\SdkGenerator\Ir\ApiDef;
use NovaHorizons\SdkGenerator\Ir\EnumDef;
use NovaHorizons\SdkGenerator\Ir\ObjectDef;
use NovaHorizons\SdkGenerator\Ir\OperationDef;
use NovaHorizons\SdkGenerator\Ir\ParamDef;
use NovaHorizons\SdkGenerator\Ir\PropertyDef;
use NovaHorizons\SdkGenerator\Ir\TypeKind;
use NovaHorizons\SdkGenerator\Ir\TypeRef;

/**
 * Maps a loaded OpenAPI document to the intermediate representation.
 *
 * Works from the raw document array (already validated by cebe) so that
 * $ref names are preserved — they drive DTO class names.
 */
final class Mapper
{
    /** @var array<string, mixed> */
    private array $raw;

    /** @var array<string, ObjectDef> */
    private array $objects = [];

    /** @var array<string, EnumDef> */
    private array $enums = [];

    /** @var array<string, string> schema name => class name (memo) */
    private array $schemaClasses = [];

    /** @var array<string, string> structural hash => class name, for untitled inline schemas */
    private array $inlineByShape = [];

    /** @var array<string, true> */
    private array $requestSide = [];

    /** @var array<string, true> */
    private array $responseSide = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<SpecViolation> */
    public array $violations = [];

    public function __construct(private readonly Config $config) {}

    private function record(string $rule, string $location, string $message, string $fix): void
    {
        if (in_array($rule, $this->config->allow, true)) {
            $this->warnings[] = "[{$rule}] {$location}: {$message} (allowed)";

            return;
        }

        $this->violations[] = new SpecViolation($rule, $location, $message, $fix);
    }

    /** @param array<string, mixed> $schema */
    private function isUntyped(array $schema): bool
    {
        if (isset($schema['$ref']) || isset($schema['allOf']) || isset($schema['oneOf']) || isset($schema['anyOf'])) {
            return false;
        }
        if (($schema['type'] ?? null) === 'array') {
            $items = $schema['items'] ?? null;

            return ! is_array($items) || $items === [] || $this->isUntyped($items);
        }
        if (isset($schema['properties']) && $schema['properties'] !== []) {
            return false;
        }
        if (is_array($schema['additionalProperties'] ?? null) && $schema['additionalProperties'] !== []) {
            return false;
        }
        if (in_array($schema['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)) {
            return false;
        }

        return true;
    }

    public function map(LoadedSpec $spec): ApiDef
    {
        $this->raw = $spec->raw;

        $this->classifySchemaUsage();

        // Map all named component schemas first so refs always resolve to stable names.
        foreach ($this->componentSchemas() as $name => $schema) {
            $this->mapNamedSchema($name);
        }

        $resources = $this->mapOperations();

        $this->markSentinels();

        $info = $this->raw['info'] ?? [];
        [$apiKeyHeader, $bearerAuth] = $this->auth();

        return new ApiDef(
            title: is_string($info['title'] ?? null) ? $info['title'] : 'Api',
            version: is_string($info['version'] ?? null) ? $info['version'] : '0.0.0',
            objects: $this->objects,
            enums: $this->enums,
            resources: $resources,
            apiKeyHeader: $apiKeyHeader,
            bearerAuth: $bearerAuth,
            errorClass: $this->errorClass(),
            serverUrl: $this->serverUrl(),
        );
    }

    /**
     * servers[0].url, when it can serve as a default base URL for the client.
     * Config always overrides; localhost/relative URLs (usually whatever host
     * generated the spec) are rejected with a nudge to fix the spec.
     */
    private function serverUrl(): ?string
    {
        $servers = $this->raw['servers'] ?? null;
        if (! is_array($servers) || $servers === []) {
            return null;
        }

        $url = is_array($servers[0] ?? null) ? ($servers[0]['url'] ?? null) : null;
        if (! is_string($url) || $url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $usable = in_array($scheme, ['http', 'https'], true)
            && is_string($host)
            && ! in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true);

        if (! $usable) {
            $this->warnings[] = "servers[0] \"{$url}\" is not a usable default base URL (relative or localhost) — declare the API's canonical URL in the spec to give generated clients a default";

            return null;
        }

        return rtrim($url, '/');
    }

    // ---------------------------------------------------------------- schemas

    /** @return array<string, array<string, mixed>> */
    private function componentSchemas(): array
    {
        $schemas = $this->raw['components']['schemas'] ?? [];

        return is_array($schemas) ? $schemas : [];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: ?string, 1: array<string, mixed>} [schemaName, schema]
     */
    private function resolve(array $node): array
    {
        $seen = [];
        $name = null;

        while (isset($node['$ref'])) {
            $ref = $node['$ref'];
            if (isset($seen[$ref])) {
                throw new \RuntimeException("Circular \$ref: {$ref}");
            }
            $seen[$ref] = true;

            if (! is_string($ref) || ! str_starts_with($ref, '#/')) {
                throw new \RuntimeException('Only internal $refs are supported, got: '.var_export($ref, true));
            }

            $name = self::schemaNameFromRef($ref) ?? $name;

            $segments = explode('/', substr($ref, 2));
            $target = $this->raw;
            foreach ($segments as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (! is_array($target) || ! array_key_exists($segment, $target)) {
                    throw new \RuntimeException("Unresolvable \$ref: {$ref}");
                }
                $target = $target[$segment];
            }
            $node = $target;
        }

        return [$name, $node];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: ?string, 1: array<string, mixed>} [schemaName, schema]
     */
    private function resolveIfRef(array $node): array
    {
        return isset($node['$ref']) ? $this->resolve($node) : [null, $node];
    }

    /** Schema name from an internal component-schema $ref (not a deeper pointer), else null. */
    private static function schemaNameFromRef(mixed $ref): ?string
    {
        return is_string($ref) && preg_match('#^\#/components/schemas/([^/]+)$#', $ref, $m) ? $m[1] : null;
    }

    private function mapNamedSchema(string $name): TypeRef
    {
        if (isset($this->schemaClasses[$name])) {
            $className = $this->schemaClasses[$name];
            $kind = isset($this->enums[$className]) ? TypeKind::Enum : TypeKind::Object;

            return new TypeRef($kind, $className);
        }

        $schema = $this->componentSchemas()[$name] ?? null;
        if ($schema === null) {
            throw new \RuntimeException("Unknown schema: {$name}");
        }

        $className = Names::className($name);
        // Reserve the name before recursing so self-references resolve.
        $this->schemaClasses[$name] = $className;

        return $this->mapSchema($schema, $className, forcedClassName: $className);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function mapSchema(array $schema, string $nameHint, ?string $forcedClassName = null): TypeRef
    {
        if (isset($schema['$ref'])) {
            [$name, $schema] = $this->resolve($schema);
            if ($name !== null) {
                return $this->mapNamedSchema($name);
            }
        }

        $nullable = ($schema['nullable'] ?? false) === true;

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            return $this->mapObject($this->flattenAllOf($schema), $nameHint, $forcedClassName, false)->with($nullable);
        }

        if (isset($schema['oneOf']) || isset($schema['anyOf'])) {
            $variants = $schema['oneOf'] ?? $schema['anyOf'];

            // Single-variant oneOf/anyOf is how swagger-php expresses a nullable
            // $ref in OpenAPI 3.0 — semantically it's just that schema.
            if (is_array($variants) && count($variants) === 1 && is_array($variants[0])) {
                return $this->mapSchema($variants[0], $nameHint)->with($nullable);
            }

            $this->record(
                SpecViolation::ONE_OF_ANY_OF,
                $nameHint,
                'oneOf/anyOf degrades to mixed — no type safety for this value',
                'Replace with a single schema, or a discriminated type the consumers can switch on',
            );

            return TypeRef::mixed();
        }

        $type = $schema['type'] ?? null;

        // Enums (string/int backed)
        if (isset($schema['enum']) && is_array($schema['enum']) && in_array($type, ['string', 'integer'], true)) {
            return $this->mapEnum($schema, $forcedClassName ?? $nameHint)->with($nullable);
        }

        return match ($type) {
            'string' => new TypeRef(match ($schema['format'] ?? null) {
                'date' => TypeKind::Date,
                'date-time' => TypeKind::DateTime,
                default => TypeKind::String,
            }, nullable: $nullable),
            'integer' => new TypeRef(TypeKind::Int, nullable: $nullable),
            'number' => new TypeRef(TypeKind::Float, nullable: $nullable),
            'boolean' => new TypeRef(TypeKind::Bool, nullable: $nullable),
            'array' => $this->mapArray($schema, $nameHint, $nullable),
            'object' => $this->mapObject($schema, $nameHint, $forcedClassName, $nullable),
            default => isset($schema['properties'])
                ? $this->mapObject($schema, $nameHint, $forcedClassName, $nullable)
                : TypeRef::mixed(),
        };
    }

    /** @param array<string, mixed> $schema */
    private function mapArray(array $schema, string $nameHint, bool $nullable): TypeRef
    {
        $items = $schema['items'] ?? [];
        $itemHint = Str::singular($nameHint);
        $itemType = is_array($items) && $items !== []
            ? $this->mapSchema($items, $itemHint)
            : TypeRef::mixed();

        return new TypeRef(TypeKind::ArrayOf, items: $itemType, nullable: $nullable);
    }

    /** @param array<string, mixed> $schema */
    private function mapObject(array $schema, string $nameHint, ?string $forcedClassName, bool $nullable): TypeRef
    {
        $properties = $schema['properties'] ?? null;

        if (! is_array($properties) || $properties === []) {
            $additional = $schema['additionalProperties'] ?? null;
            if (is_array($additional) && $additional !== []) {
                return new TypeRef(TypeKind::Map, items: $this->mapSchema($additional, $nameHint.'Value'), nullable: $nullable);
            }

            // Untyped object => array<string, mixed>
            return new TypeRef(TypeKind::Map, items: TypeRef::mixed(), nullable: $nullable);
        }

        $className = $forcedClassName ?? $this->hoistedName($schema, $nameHint, $properties);

        if (! isset($this->objects[$className])) {
            // Reserve before recursing (self-referencing schemas).
            $this->objects[$className] = new ObjectDef($className, []);
            $this->objects[$className] = $this->buildObjectDef($className, $schema);
        }

        return new TypeRef(TypeKind::Object, $className, nullable: $nullable);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $properties
     */
    private function hoistedName(array $schema, string $nameHint, array $properties): string
    {
        if (is_string($schema['title'] ?? null) && $schema['title'] !== '') {
            return $this->uniqueClassName(Names::className($schema['title']));
        }

        // Structural dedupe for untitled inline schemas (e.g. repeated {rows_affected}).
        $shape = md5(json_encode([$properties, $schema['required'] ?? []], JSON_THROW_ON_ERROR));
        if (isset($this->inlineByShape[$shape])) {
            return $this->inlineByShape[$shape];
        }

        $name = $this->uniqueClassName(Names::className($nameHint));
        $this->inlineByShape[$shape] = $name;

        return $name;
    }

    private function uniqueClassName(string $base): string
    {
        $name = $base;
        $i = 2;
        while (isset($this->objects[$name]) || isset($this->enums[$name])) {
            $name = $base.$i;
            $i++;
        }

        return $name;
    }

    /** @param array<string, mixed> $schema */
    private function buildObjectDef(string $className, array $schema): ObjectDef
    {
        $required = $schema['required'] ?? [];
        $required = is_array($required) ? $required : [];

        $props = [];
        $phpNames = [];

        foreach (($schema['properties'] ?? []) as $wireName => $propSchema) {
            if (! is_array($propSchema)) {
                continue;
            }

            $phpName = Names::property((string) $wireName);
            if (isset($phpNames[$phpName])) {
                throw new \RuntimeException(
                    "Property name collision in {$className}: '{$wireName}' and '{$phpNames[$phpName]}' both map to \${$phpName}"
                );
            }
            $phpNames[$phpName] = (string) $wireName;

            $type = $this->mapSchema($propSchema, $className.Str::studly(Names::property((string) $wireName)));

            $props[] = new PropertyDef(
                wireName: (string) $wireName,
                phpName: $phpName,
                type: $type,
                required: in_array($wireName, $required, true),
                description: is_string($propSchema['description'] ?? null) ? $propSchema['description'] : null,
                example: $propSchema['example'] ?? null,
                deprecated: ($propSchema['deprecated'] ?? false) === true,
            );
        }

        return new ObjectDef(
            className: $className,
            properties: $props,
            description: is_string($schema['description'] ?? null) ? $schema['description'] : null,
            deprecated: ($schema['deprecated'] ?? false) === true,
        );
    }

    /**
     * Merges an allOf schema's parts into one object schema (merge, never
     * inheritance). Later parts override earlier properties; the schema's own
     * description wins, else the first part description found.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function flattenAllOf(array $schema): array
    {
        $merged = ['type' => 'object', 'properties' => [], 'required' => []];
        if (isset($schema['description'])) {
            $merged['description'] = $schema['description'];
        }

        foreach ($schema['allOf'] as $part) {
            if (! is_array($part)) {
                continue;
            }
            [, $resolved] = $this->resolveIfRef($part);

            if (isset($resolved['allOf'])) {
                $resolved = $this->flattenAllOf($resolved);
            }

            foreach (($resolved['properties'] ?? []) as $name => $prop) {
                $merged['properties'][$name] = $prop;
            }
            foreach (($resolved['required'] ?? []) as $req) {
                $merged['required'][] = $req;
            }
            if (! isset($merged['description']) && isset($resolved['description'])) {
                $merged['description'] = $resolved['description'];
            }
        }

        $merged['required'] = array_values(array_unique($merged['required']));

        return $merged;
    }

    /** @param array<string, mixed> $schema */
    private function mapEnum(array $schema, string $nameHint): TypeRef
    {
        $backingType = ($schema['type'] ?? 'string') === 'integer' ? 'int' : 'string';
        $className = $this->uniqueClassName(Names::className($nameHint));

        $cases = [];
        foreach ($schema['enum'] as $value) {
            if ($value === null) {
                continue; // nullable is handled on the property
            }
            $cases[Names::enumCase($value)] = $backingType === 'int' ? (int) $value : (string) $value;
        }

        $this->enums[$className] = new EnumDef(
            className: $className,
            backingType: $backingType,
            cases: $cases,
            description: is_string($schema['description'] ?? null) ? $schema['description'] : null,
        );

        return new TypeRef(TypeKind::Enum, $className);
    }

    // ------------------------------------------------------------- operations

    /** @return array<string, list<OperationDef>> */
    private function mapOperations(): array
    {
        $resources = [];

        foreach (($this->raw['paths'] ?? []) as $path => $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            $pathLevelParams = $pathItem['parameters'] ?? [];

            foreach (['get', 'post', 'put', 'patch', 'delete'] as $httpMethod) {
                $op = $pathItem[$httpMethod] ?? null;
                if (! is_array($op)) {
                    continue;
                }

                $operationId = is_string($op['operationId'] ?? null) && $op['operationId'] !== '';
                if (! $operationId) {
                    $this->record(
                        SpecViolation::MISSING_OPERATION_ID,
                        strtoupper($httpMethod).' '.$path,
                        'no operationId — the generated method name is derived from the path and will churn if the path changes',
                        'Add a stable operationId to the operation',
                    );
                }
                $operationId = $operationId
                    ? $op['operationId']
                    : $httpMethod.Str::studly(str_replace(['/', '{', '}'], ' ', (string) $path));

                $tag = is_array($op['tags'] ?? null) && isset($op['tags'][0]) && is_string($op['tags'][0])
                    ? $op['tags'][0]
                    : 'Default';

                $resource = Names::resource($tag);

                $resources[$resource][] = $this->mapOperation(
                    (string) $path,
                    $httpMethod,
                    $operationId,
                    $op,
                    is_array($pathLevelParams) ? array_values($pathLevelParams) : [],
                );
            }
        }

        ksort($resources);

        return $resources;
    }

    /**
     * @param  array<string, mixed>  $op
     * @param  list<mixed>  $pathLevelParams
     */
    private function mapOperation(string $path, string $httpMethod, string $operationId, array $op, array $pathLevelParams): OperationDef
    {
        $opClass = Str::studly(Names::method($operationId));

        // ---- parameters
        $params = [];
        $seen = [];
        foreach (array_merge($pathLevelParams, $op['parameters'] ?? []) as $param) {
            if (! is_array($param)) {
                continue;
            }
            [, $param] = $this->resolveIfRef($param);

            $in = $param['in'] ?? null;
            $wireName = $param['name'] ?? null;
            if (! is_string($wireName) || ! in_array($in, ['path', 'query'], true)) {
                if ($in === 'header' || $in === 'cookie') {
                    $this->record(
                        SpecViolation::SKIPPED_PARAM,
                        $operationId,
                        "{$in} parameter '{$wireName}' is not representable in the generated client and was skipped",
                        'Move it to a query/path parameter, or handle it via the client PendingRequest',
                    );
                }

                continue;
            }
            if (isset($seen[$in.':'.$wireName])) {
                continue; // op-level already overrides path-level (merged in order)
            }
            $seen[$in.':'.$wireName] = true;

            $schema = is_array($param['schema'] ?? null) ? $param['schema'] : [];
            $allowedValues = null;
            if (isset($schema['enum']) && is_array($schema['enum'])) {
                $allowedValues = array_values($schema['enum']);
                $schema = ['type' => $schema['type'] ?? 'string']; // inline param enums stay scalars
            }

            $params[] = new ParamDef(
                wireName: $wireName,
                phpName: Names::property($wireName),
                in: $in,
                type: $this->mapSchema($schema, $opClass.Str::studly($wireName)),
                required: ($param['required'] ?? false) === true || $in === 'path',
                description: is_string($param['description'] ?? null) ? $param['description'] : null,
                allowedValues: $allowedValues,
            );
        }

        // Path params first, in path order; then required query params before optional ones (spec order within each group).
        usort($params, function (ParamDef $a, ParamDef $b) use ($path): int {
            if ($a->in !== $b->in) {
                return $a->in === 'path' ? -1 : 1;
            }
            if ($a->in === 'path') {
                return strpos($path, '{'.$a->wireName.'}') <=> strpos($path, '{'.$b->wireName.'}');
            }

            return ($a->required ? 0 : 1) <=> ($b->required ? 0 : 1);
        });

        // ---- request body
        $bodyType = null;
        $bodyRequired = false;
        $requestBody = $op['requestBody'] ?? null;
        if (is_array($requestBody)) {
            [, $requestBody] = $this->resolveIfRef($requestBody);
            $bodyRequired = ($requestBody['required'] ?? false) === true;

            $jsonContent = $requestBody['content']['application/json'] ?? null;
            if (is_array($jsonContent) && is_array($jsonContent['schema'] ?? null)) {
                $bodyType = $this->mapSchema($jsonContent['schema'], $opClass.'Request');
            } elseif (is_array($requestBody['content'] ?? null) && $requestBody['content'] !== []) {
                $this->record(
                    SpecViolation::NON_JSON_BODY,
                    $operationId,
                    'request body has no application/json content — the generated method sends no body',
                    'Document an application/json request body',
                );
            }
        }

        // ---- responses
        $returnType = null;
        $responses = is_array($op['responses'] ?? null) ? $op['responses'] : [];
        ksort($responses);
        $undocumentedErrors = [];
        $successSeen = false;
        foreach ($responses as $status => $response) {
            if (! is_array($response)) {
                continue;
            }
            [, $response] = $this->resolveIfRef($response);
            $jsonContent = $response['content']['application/json'] ?? null;
            $schema = is_array($jsonContent) && is_array($jsonContent['schema'] ?? null) ? $jsonContent['schema'] : null;

            if (! preg_match('/^2\d\d$/', (string) $status)) {
                if ($schema === null && (string) $status !== 'default') {
                    $undocumentedErrors[] = (string) $status;
                }

                continue;
            }

            if ($successSeen) {
                continue; // first 2xx wins
            }
            $successSeen = true;

            if ($schema !== null) {
                if ($this->isUntyped($schema)) {
                    $this->record(
                        SpecViolation::UNTYPED_RESPONSE,
                        $operationId,
                        'success response body is untyped ({} or bare object/array) — consumers get mixed',
                        'Reference a component schema, or describe properties/items',
                    );
                }
                $returnType = $this->mapSchema($schema, $opClass.'Response');
            }
        }

        if ($undocumentedErrors !== []) {
            $this->record(
                SpecViolation::MISSING_ERROR_SCHEMA,
                $operationId,
                'error responses ('.implode(', ', $undocumentedErrors).') have no body schema — RequestException::error() cannot be generated',
                'Document error bodies with a shared Error schema ref',
            );
        }

        return new OperationDef(
            operationId: $operationId,
            deprecated: ($op['deprecated'] ?? false) === true,
            methodName: Names::method($operationId),
            httpMethod: $httpMethod,
            path: $path,
            params: $params,
            bodyType: $bodyType,
            bodyRequired: $bodyRequired,
            returnType: $returnType,
            summary: is_string($op['summary'] ?? null) ? $op['summary'] : null,
        );
    }

    // ---------------------------------------------------------- usage/sentinel

    private function classifySchemaUsage(): void
    {
        foreach (($this->raw['paths'] ?? []) as $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }
            foreach ($pathItem as $op) {
                if (! is_array($op)) {
                    continue;
                }
                if (isset($op['requestBody'])) {
                    foreach ($this->collectRefs($op['requestBody']) as $name) {
                        $this->requestSide[$name] = true;
                    }
                }
                if (isset($op['responses'])) {
                    foreach ($this->collectRefs($op['responses']) as $name) {
                        $this->responseSide[$name] = true;
                    }
                }
            }
        }

        // Expand transitively through the schema graph.
        $this->requestSide = $this->expandUsage($this->requestSide);
        $this->responseSide = $this->expandUsage($this->responseSide);
    }

    /**
     * @param  array<string, true>  $set
     * @return array<string, true>
     */
    private function expandUsage(array $set): array
    {
        $schemas = $this->componentSchemas();
        $queue = array_keys($set);

        while ($queue !== []) {
            $name = array_shift($queue);
            foreach ($this->collectRefs($schemas[$name] ?? []) as $child) {
                if (! isset($set[$child])) {
                    $set[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return $set;
    }

    /** @return list<string> schema names referenced anywhere under $node */
    private function collectRefs(mixed $node): array
    {
        $names = [];
        $walk = function (mixed $n) use (&$walk, &$names): void {
            if (! is_array($n)) {
                return;
            }
            $name = self::schemaNameFromRef($n['$ref'] ?? null);
            if ($name !== null) {
                $names[] = $name;
            }
            foreach ($n as $child) {
                $walk($child);
            }
        };
        $walk($node);

        return array_values(array_unique($names));
    }

    private function markSentinels(): void
    {
        foreach ($this->schemaClasses as $schemaName => $className) {
            $object = $this->objects[$className] ?? null;
            if ($object === null) {
                continue;
            }

            $object->sentinelStyle = isset($this->requestSide[$schemaName])
                && ! isset($this->responseSide[$schemaName])
                && $object->properties !== [];
        }
    }

    /**
     * If every documented non-2xx response body references the same schema,
     * the generated RequestException exposes a typed error() accessor for it.
     */
    private function errorClass(): ?string
    {
        $names = [];

        foreach (($this->raw['paths'] ?? []) as $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }
            foreach ($pathItem as $op) {
                if (! is_array($op) || ! is_array($op['responses'] ?? null)) {
                    continue;
                }
                foreach ($op['responses'] as $status => $response) {
                    if (preg_match('/^2\d\d$/', (string) $status) || ! is_array($response)) {
                        continue;
                    }
                    $schema = $response['content']['application/json']['schema'] ?? null;
                    if (! is_array($schema)) {
                        continue;
                    }
                    $name = self::schemaNameFromRef($schema['$ref'] ?? null);
                    if ($name !== null) {
                        $names[$name] = true;
                    }
                    // inline error bodies are ignored — they don't disqualify the rest
                }
            }
        }

        if (count($names) !== 1) {
            return null;
        }

        $name = array_key_first($names);

        return $this->schemaClasses[$name] ?? null;
    }

    // ---------------------------------------------------------------- security

    /** @return array{0: ?string, 1: bool} [apiKeyHeader, bearerAuth] from the first supported security scheme */
    private function auth(): array
    {
        foreach (($this->raw['components']['securitySchemes'] ?? []) as $scheme) {
            if (! is_array($scheme)) {
                continue;
            }
            if (($scheme['type'] ?? null) === 'apiKey' && ($scheme['in'] ?? null) === 'header' && is_string($scheme['name'] ?? null)) {
                return [$scheme['name'], false];
            }
            if (($scheme['type'] ?? null) === 'http' && ($scheme['scheme'] ?? null) === 'bearer') {
                return ['Authorization', true];
            }
        }

        return [null, false];
    }
}
