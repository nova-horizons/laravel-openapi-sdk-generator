<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Emitter;

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use NovaHorizons\SdkGenerator\Ir\OperationDef;
use NovaHorizons\SdkGenerator\Ir\ParamDef;
use NovaHorizons\SdkGenerator\Ir\TypeKind;
use NovaHorizons\SdkGenerator\Ir\TypeRef;

final readonly class ResourceEmitter
{
    public function __construct(
        private string $namespace,
        private Types $types,
    ) {}

    private function cast(string $method, string $args, string $path): string
    {
        return $this->types->castClass()."::{$method}({$args}, ".var_export($path, true).')';
    }

    /** @param list<OperationDef> $operations */
    public function emit(string $resourceName, array $operations): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes();

        $namespace = $file->addNamespace($this->namespace.'\\Resources');
        $namespace->addUse('Illuminate\\Http\\Client\\Response');

        $class = $namespace->addClass($resourceName.'Resource');
        $class->setFinal()->setReadOnly();
        $class->setExtends($this->namespace.'\\Resources\\Resource');

        foreach ($operations as $operation) {
            $this->addOperation($namespace, $class, $operation);

            if ($this->isPaginated($operation)) {
                $this->addLazyVariant($namespace, $class, $operation);
            }
        }

        return $file;
    }

    /** GET returning a list of DTOs, with int offset+limit query params. */
    private function isPaginated(OperationDef $op): bool
    {
        if ($op->httpMethod !== 'get'
            || $op->returnType?->kind !== TypeKind::ArrayOf
            || ($op->returnType->items ?? null)?->kind !== TypeKind::Object) {
            return false;
        }

        $found = 0;
        foreach ($op->params as $param) {
            if ($param->in === 'query'
                && in_array($param->wireName, ['offset', 'limit'], true)
                && $param->type->kind === TypeKind::Int) {
                $found++;
            }
        }

        return $found === 2;
    }

    private function addLazyVariant(PhpNamespace $namespace, ClassType $class, OperationDef $op): void
    {
        $namespace->addUse('Illuminate\\Support\\LazyCollection');

        $items = $op->returnType?->items;
        $itemClass = $this->types->dtoClass((string) $items?->className);

        $method = $class->addMethod($op->methodName.'Lazy');
        $method->addComment("Auto-paging variant of {$op->methodName}() — iterates every row,");
        $method->addComment('fetching $chunkSize per request. Requests are made lazily during iteration.');
        $method->addComment('');
        $method->addComment('@return LazyCollection<int, '.$namespace->simplifyName($itemClass).'>');

        $passthrough = array_values(array_filter(
            $op->params,
            fn (ParamDef $p): bool => ! ($p->in === 'query' && in_array($p->wireName, ['offset', 'limit'], true)),
        ));

        $args = [];
        $uses = [];
        foreach ($passthrough as $param) {
            $parameter = $method->addParameter($param->phpName)->setType($this->types->native($param->type));
            if ($param->in !== 'path' && (! $param->required || $param->type->nullable)) {
                $parameter->setNullable()->setDefaultValue(null);
            }
            $this->paramDoc($method, $param);
            $args[] = "{$param->phpName}: \${$param->phpName}";
            $uses[] = '$'.$param->phpName;
        }

        $method->addParameter('chunkSize')->setType('int')->setDefaultValue(500);
        $method->setReturnType('Illuminate\\Support\\LazyCollection');

        $uses[] = '$chunkSize';
        $argList = $args === [] ? '' : implode(', ', $args).', ';

        $lines = [
            'return LazyCollection::make(function () use ('.implode(', ', $uses).'): \\Generator {',
            '    $offset = 0;',
            '',
            '    do {',
            "        \$page = \$this->{$op->methodName}({$argList}offset: \$offset, limit: \$chunkSize);",
            '',
            '        foreach ($page as $item) {',
            '            yield $item;',
            '        }',
            '',
            '        $offset += $chunkSize;',
            '    } while ($page->count() === $chunkSize);',
            '});',
        ];

        $method->setBody(implode("\n", $this->simplifyLines($namespace, $lines)));
    }

    private function addOperation(PhpNamespace $namespace, ClassType $class, OperationDef $op): void
    {
        $method = $class->addMethod($op->methodName);

        if ($op->summary !== null) {
            $method->addComment($op->summary);
            $method->addComment('');
        }
        $method->addComment(strtoupper($op->httpMethod).' '.$op->path);
        if ($op->deprecated) {
            $method->addComment('');
            $method->addComment('@deprecated per the OpenAPI spec');
        }

        $pathParams = array_values(array_filter($op->params, fn (ParamDef $p): bool => $p->in === 'path'));
        $queryParams = array_values(array_filter($op->params, fn (ParamDef $p): bool => $p->in === 'query'));
        $requiredQuery = array_values(array_filter($queryParams, fn (ParamDef $p): bool => $p->required));
        $optionalQuery = array_values(array_filter($queryParams, fn (ParamDef $p): bool => ! $p->required));

        // --- parameters: path, required body, required query, optional body, optional query
        foreach ($pathParams as $param) {
            $method->addParameter($param->phpName)->setType($this->types->native($param->type));
            $this->paramDoc($method, $param);
        }

        if ($op->bodyType !== null && $op->bodyRequired) {
            $this->addBodyParameter($method, $op->bodyType, required: true);
        }

        foreach ($requiredQuery as $param) {
            $method->addParameter($param->phpName)->setType($this->types->native($param->type));
            $this->paramDoc($method, $param);
        }

        if ($op->bodyType !== null && ! $op->bodyRequired) {
            $this->addBodyParameter($method, $op->bodyType, required: false);
        }

        foreach ($optionalQuery as $param) {
            $method->addParameter($param->phpName)
                ->setType($this->types->native($param->type))
                ->setNullable()
                ->setDefaultValue(null);
            $this->paramDoc($method, $param);
        }

        // --- body lines
        $lines = [];

        $hasNullableQuery = array_filter($queryParams, fn (ParamDef $p): bool => ! $p->required || $p->type->nullable) !== [];
        $hasRequiredQuery = $requiredQuery !== [];

        if ($queryParams !== []) {
            $entries = [];
            foreach ($queryParams as $param) {
                $entries[] = sprintf(
                    '    %s => %s,',
                    var_export($param->wireName, true),
                    $this->queryValue($param),
                );
            }

            if ($hasNullableQuery) {
                $lines[] = '$query = array_filter([';
                array_push($lines, ...$entries);
                $lines[] = '], static fn (mixed $value): bool => $value !== null);';
            } else {
                $lines[] = '$query = [';
                array_push($lines, ...$entries);
                $lines[] = '];';
            }
            $lines[] = '';
        }

        $pathExpr = $this->pathExpression($op, $pathParams);

        $httpCall = $this->httpCall($op, $pathExpr, $queryParams !== [], $hasRequiredQuery, $lines);

        $this->addReturn($namespace, $method, $op, $httpCall, $lines);

        $namespace->addUse($this->namespace.'\\Exceptions\\ConnectionException');
        $namespace->addUse($this->namespace.'\\Exceptions\\RequestException');
        $method->addComment('@throws ConnectionException on transport errors');
        $method->addComment('@throws RequestException on 4xx/5xx responses');

        // Only hydrating methods can throw UnexpectedResponseException — void
        // operations never touch Cast, and declaring it would be too wide.
        if ($op->returnType !== null && $op->returnType->kind !== TypeKind::Mixed) {
            $namespace->addUse($this->namespace.'\\Exceptions\\UnexpectedResponseException');
            $method->addComment('@throws UnexpectedResponseException when the response defies the spec');
        }

        $method->setBody(implode("\n", $this->simplifyLines($namespace, $lines)));
    }

    /** Expression for a query-array value; null values are filtered out. */
    private function queryValue(ParamDef $param): string
    {
        $access = '$'.$param->phpName;
        $op = $param->required && ! $param->type->nullable ? '->' : '?->';

        return match ($param->type->kind) {
            TypeKind::Date => "{$access}{$op}format('Y-m-d')",
            TypeKind::DateTime => "{$access}{$op}toIso8601String()",
            TypeKind::Bool => $param->required ? "{$access} ? '1' : '0'" : "{$access} === null ? null : ({$access} ? '1' : '0')",
            default => $access,
        };
    }

    private function addBodyParameter(Method $method, TypeRef $bodyType, bool $required): void
    {
        $native = $this->types->native($bodyType);
        $param = $method->addParameter('body')->setType($native);

        if (! $required) {
            $param->setNullable()->setDefaultValue($native === 'array' ? [] : null);
        }

        $doc = $this->types->doc($bodyType);
        if ($this->types->needsDoc($bodyType)) {
            $method->addComment("@param {$doc} \$body");
        }
    }

    private function paramDoc(Method $method, ParamDef $param): void
    {
        $notes = [];
        if ($param->description !== null) {
            $notes[] = $param->description;
        }
        if ($param->allowedValues !== null) {
            $notes[] = 'One of: '.implode(', ', array_map(strval(...), $param->allowedValues));
        }

        if ($notes !== []) {
            $doc = $this->types->doc($param->type);
            if (! $param->required || $param->type->nullable) {
                $doc .= '|null';
            }
            $method->addComment("@param {$doc} \${$param->phpName} ".implode(' — ', $notes));
        }
    }

    /** @param list<ParamDef> $pathParams */
    private function pathExpression(OperationDef $op, array $pathParams): string
    {
        $byWire = [];
        foreach ($pathParams as $param) {
            $byWire[$param->wireName] = $param;
        }

        $parts = preg_split('/(\{[^}]+\})/', $op->path, flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $exprs = [];

        foreach ($parts ?: [] as $part) {
            if (preg_match('/^\{(.+)\}$/', $part, $m)) {
                $param = $byWire[$m[1]] ?? throw new \RuntimeException(
                    "Path parameter '{$m[1]}' of {$op->operationId} is not declared in the spec"
                );
                $exprs[] = match ($param->type->kind) {
                    TypeKind::Int, TypeKind::Float => "\${$param->phpName}",
                    default => "rawurlencode(\${$param->phpName})",
                };
            } else {
                $exprs[] = var_export($part, true);
            }
        }

        return implode('.', $exprs);
    }

    /** @param list<string> $lines */
    private function httpCall(OperationDef $op, string $pathExpr, bool $hasQuery, bool $hasRequiredQuery, array &$lines): string
    {
        if ($op->httpMethod === 'get') {
            return $hasQuery
                ? "\$this->http->get({$pathExpr}, \$query)"
                : "\$this->http->get({$pathExpr})";
        }

        $urlExpr = $pathExpr;
        if ($hasQuery) {
            if ($hasRequiredQuery) {
                // At least one query entry always present — append unconditionally.
                $lines[] = "\$url = {$pathExpr}.'?'.http_build_query(\$query);";
            } else {
                $lines[] = "\$url = {$pathExpr};";
                $lines[] = 'if ($query !== []) {';
                $lines[] = "    \$url .= '?'.http_build_query(\$query);";
                $lines[] = '}';
            }
            $lines[] = '';
            $urlExpr = '$url';
        }

        $bodyExpr = match (true) {
            $op->bodyType === null => null,
            $op->bodyType->kind === TypeKind::Object && $op->bodyRequired => '$body->jsonSerialize()',
            $op->bodyType->kind === TypeKind::Object => '$body?->jsonSerialize() ?? []',
            default => '$body',
        };

        return $bodyExpr === null
            ? "\$this->http->{$op->httpMethod}({$urlExpr})"
            : "\$this->http->{$op->httpMethod}({$urlExpr}, {$bodyExpr})";
    }

    /** @param list<string> $lines */
    private function addReturn(PhpNamespace $namespace, Method $method, OperationDef $op, string $httpCall, array &$lines): void
    {
        $returnType = $op->returnType;
        $rpath = $op->methodName.' response';

        if ($returnType === null) {
            $method->setReturnType('void');
            $lines[] = "\$this->send(fn (): Response => {$httpCall});";

            return;
        }

        switch ($returnType->kind) {
            case TypeKind::ArrayOf:
                $namespace->addUse('Illuminate\\Support\\Collection');
                $items = $returnType->items ?? TypeRef::mixed();
                $itemDoc = $this->types->doc($items);
                $method->setReturnType('Illuminate\\Support\\Collection');
                $method->addComment("@return Collection<int, {$itemDoc}>");

                $lines[] = "\$response = \$this->send(fn (): Response => {$httpCall});";
                $lines[] = '$data = '.$this->cast('toList', '$response->json() ?? []', $rpath).';';
                $lines[] = '';
                if ($items->kind === TypeKind::Object) {
                    $itemClass = $this->types->dtoClass((string) $items->className);
                    $lines[] = "return (new Collection(\$data))->map(static fn (mixed \$item): {$itemClass} => {$itemClass}::fromArray(".$this->cast('toArray', '$item', $rpath.'[]').'));';
                } elseif (in_array($items->kind, [TypeKind::String, TypeKind::Int, TypeKind::Float, TypeKind::Bool], true)) {
                    $native = $this->types->native($items);
                    $castMethod = 'to'.ucfirst($native);
                    $lines[] = "return (new Collection(\$data))->map(static fn (mixed \$value): {$native} => ".$this->cast($castMethod, '$value', $rpath.'[]').');';
                } else {
                    $lines[] = 'return new Collection($data);';
                }
                break;

            case TypeKind::Object:
                $class = $this->types->dtoClass((string) $returnType->className);
                $method->setReturnType($class);
                $lines[] = "\$response = \$this->send(fn (): Response => {$httpCall});";
                $lines[] = '';
                $lines[] = "return {$class}::fromArray(".$this->cast('toArray', '$response->json()', $rpath).');';
                break;

            case TypeKind::Enum:
                $class = $this->types->enumClass((string) $returnType->className);
                $method->setReturnType($class);
                $lines[] = "\$response = \$this->send(fn (): Response => {$httpCall});";
                $lines[] = '';
                $lines[] = "return {$class}::from(".$this->cast('toString', '$response->json()', $rpath).');';
                break;

            case TypeKind::String:
            case TypeKind::Int:
            case TypeKind::Float:
            case TypeKind::Bool:
                $native = $this->types->native($returnType);
                $method->setReturnType($native);
                $lines[] = "\$response = \$this->send(fn (): Response => {$httpCall});";
                $lines[] = '';
                $lines[] = 'return '.$this->cast('to'.ucfirst($native), '$response->json()', $rpath).';';
                break;

            case TypeKind::Map:
                $items = $returnType->items ?? TypeRef::mixed();
                $castMethod = match ($items->kind) {
                    TypeKind::Int => 'toIntMap',
                    TypeKind::Float => 'toFloatMap',
                    TypeKind::Bool => 'toBoolMap',
                    TypeKind::String => 'toStringMap',
                    default => 'toMap',
                };
                $method->setReturnType('array');
                $method->addComment('@return '.$this->types->doc($returnType));
                $lines[] = "\$response = \$this->send(fn (): Response => {$httpCall});";
                $lines[] = '';
                $lines[] = 'return '.$this->cast($castMethod, '$response->json()', $rpath).';';
                break;

            case TypeKind::Mixed:
            default:
                $method->setReturnType('mixed');
                $lines[] = "\$response = \$this->send(fn (): Response => {$httpCall});";
                $lines[] = '';
                $lines[] = 'return $response->json();';
                break;
        }
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function simplifyLines(PhpNamespace $namespace, array $lines): array
    {
        return array_map(
            fn (string $line): string => preg_replace_callback(
                '/\\\\[A-Za-z0-9_\\\\]+/',
                fn (array $m): string => $namespace->simplifyName($m[0]),
                $line,
            ) ?? $line,
            $lines,
        );
    }
}
