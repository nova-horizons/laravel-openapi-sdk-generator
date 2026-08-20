<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Emitter;

use Illuminate\Support\Str;
use Nette\PhpGenerator\Dumper;
use Nette\PhpGenerator\PhpFile;
use NovaHorizons\SdkGenerator\Ir\ApiDef;
use NovaHorizons\SdkGenerator\Ir\ObjectDef;
use NovaHorizons\SdkGenerator\Ir\TypeKind;
use NovaHorizons\SdkGenerator\Ir\TypeRef;

/**
 * Emits Testing\{Brand}Fake:
 *
 * - a wire-array factory per response DTO, seeded from spec `example` values
 *   (`OrbitFake::beacon(['active' => false])`)
 * - a hydrated variant (`OrbitFake::beaconDto(...)`)
 * - a URL pattern constant per operation for Http::fake
 *   (`Http::fake([OrbitFake::GET_BEACON => Http::response(OrbitFake::beacon())])`)
 */
final readonly class FakeEmitter
{
    public function __construct(
        private string $namespace,
        private string $brand,
        private Types $types,
    ) {}

    public function emit(ApiDef $api): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes();

        $namespace = $file->addNamespace($this->namespace.'\\Testing');

        $class = $namespace->addClass($this->brand.'Fake');
        $class->setFinal();
        $class->addComment("Test data factories and Http::fake URL patterns for the {$this->brand} SDK.\n");
        $class->addComment('Factory arrays use wire keys and are seeded from the spec\'s example values;');
        $class->addComment('pass overrides with wire keys: '.$this->brand.'Fake::'.$this->firstFactoryName($api).'([\'field\' => ...]).');
        $class->addComment('');
        $class->addComment('Http::fake() matches URL patterns only — it never sees the HTTP method. Two');
        $class->addComment('operations on the same path (list vs create) share an identical pattern, and a');
        $class->addComment('wildcard pattern also matches deeper routes. Register more specific patterns');
        $class->addComment('first, and branch on $request->method() in a fake closure when operations share');
        $class->addComment('a URL.');

        // URL pattern constants per operation
        foreach ($api->resources as $operations) {
            foreach ($operations as $op) {
                $pattern = '*'.preg_replace('/\{[^}]+\}/', '*', $op->path);
                $constant = strtoupper(Str::snake($op->methodName));
                if (! $class->hasConstant($constant)) {
                    $class->addConstant($constant, $pattern)->setPublic()
                        ->addComment(strtoupper($op->httpMethod).' '.$op->path);
                }
            }
        }

        $dumper = new Dumper;
        $dumper->wrapLength = 100;

        foreach ($api->objects as $object) {
            if ($object->sentinelStyle || $object->properties === []) {
                continue;
            }

            $factoryName = lcfirst($object->className);
            $defaults = $this->defaults($api, $object, [$object->className => true]);

            $method = $class->addMethod($factoryName)->setStatic()->setReturnType('array');
            $method->addComment("Wire array for {$object->className}.");
            $method->addComment('');
            $method->addComment('@param array<string, mixed> $overrides wire-keyed values to replace');
            $method->addComment('@return array<string, mixed>');
            $method->addParameter('overrides')->setType('array')->setDefaultValue([]);
            $method->setBody('return array_replace('.$dumper->dump($defaults).', $overrides);');

            $dtoClass = $this->types->dtoClass($object->className);
            $namespace->addUse(ltrim($dtoClass, '\\'));

            $dtoMethod = $class->addMethod($factoryName.'Dto')->setStatic()->setReturnType($dtoClass);
            $dtoMethod->addComment('@param array<string, mixed> $overrides wire-keyed values to replace');
            $dtoMethod->addParameter('overrides')->setType('array')->setDefaultValue([]);
            $dtoMethod->setBody('return '.$namespace->simplifyName($dtoClass)."::fromArray(self::{$factoryName}(\$overrides));");
        }

        return $file;
    }

    private function firstFactoryName(ApiDef $api): string
    {
        foreach ($api->objects as $object) {
            if (! $object->sentinelStyle && $object->properties !== []) {
                return lcfirst($object->className);
            }
        }

        return 'factory';
    }

    /**
     * @param  array<string, true>  $stack  cycle guard
     * @return array<string, mixed>
     */
    private function defaults(ApiDef $api, ObjectDef $object, array $stack): array
    {
        $out = [];
        foreach ($object->properties as $property) {
            $out[$property->wireName] = $property->example ?? $this->defaultFor($api, $property->type, $property->wireName, $stack);
        }

        return $out;
    }

    /** @param array<string, true> $stack */
    private function defaultFor(ApiDef $api, TypeRef $type, string $wireName, array $stack): mixed
    {
        return match ($type->kind) {
            TypeKind::String => $wireName,
            TypeKind::Int => 1,
            TypeKind::Float => 1.5,
            TypeKind::Bool => true,
            TypeKind::Date => '2026-01-01',
            TypeKind::DateTime => '2026-01-01T00:00:00+00:00',
            TypeKind::Enum => $this->enumDefault($api, (string) $type->className),
            TypeKind::Object => $this->nested($api, (string) $type->className, $stack),
            TypeKind::ArrayOf => $this->listDefault($api, $type->items ?? TypeRef::mixed(), $wireName, $stack),
            TypeKind::Map => [],
            TypeKind::Mixed => null,
        };
    }

    /** @param array<string, true> $stack */
    private function listDefault(ApiDef $api, TypeRef $items, string $wireName, array $stack): mixed
    {
        if ($items->kind === TypeKind::Mixed) {
            return [];
        }
        if ($items->kind === TypeKind::Object && isset($stack[(string) $items->className])) {
            return [];
        }

        return [$this->defaultFor($api, $items, Str::singular($wireName), $stack)];
    }

    /**
     * @param  array<string, true>  $stack
     * @return array<string, mixed>
     */
    private function nested(ApiDef $api, string $className, array $stack): array
    {
        if (isset($stack[$className])) {
            return []; // cycle — caller overrides if needed
        }

        $object = $api->objects[$className] ?? null;
        if ($object === null) {
            return [];
        }

        $stack[$className] = true;

        return $this->defaults($api, $object, $stack);
    }

    private function enumDefault(ApiDef $api, string $className): string|int|null
    {
        $enum = $api->enums[$className] ?? null;
        if ($enum === null || $enum->cases === []) {
            return null;
        }

        return array_values($enum->cases)[0];
    }
}
