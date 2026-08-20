<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Emitter;

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use NovaHorizons\SdkGenerator\Ir\ObjectDef;
use NovaHorizons\SdkGenerator\Ir\PropertyDef;
use NovaHorizons\SdkGenerator\Ir\TypeKind;

final readonly class DtoEmitter
{
    public function __construct(
        private string $namespace,
        private Types $types,
        private Expressions $expressions,
    ) {}

    public function emit(ObjectDef $object): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes();

        $namespace = $file->addNamespace($this->namespace.'\\Dto');
        $class = $namespace->addClass($object->className);
        $class->setFinal()->setReadOnly();

        if ($object->description !== null) {
            $class->addComment($object->description);
            $class->addComment('');
        }
        if ($object->deprecated) {
            $class->addComment('@deprecated per the OpenAPI spec');
        }

        $class->addImplement(\JsonSerializable::class);

        $this->addConstructor($namespace, $class, $object);

        if (! $object->sentinelStyle) {
            $this->addFromArray($namespace, $class, $object);
        }

        $this->addJsonSerialize($namespace, $class, $object);

        return $file;
    }

    private function addConstructor(PhpNamespace $namespace, ClassType $class, ObjectDef $object): void
    {
        $constructor = $class->addMethod('__construct');

        foreach ($this->ordered($object) as $prop) {
            $param = $constructor->addPromotedParameter($prop->phpName)->setPublic();

            if ($object->sentinelStyle && ! $prop->required) {
                $omitted = $this->types->omittedClass();
                $native = $this->types->native($prop->type);
                $union = $native === 'mixed' ? 'mixed' : "{$omitted}|{$native}|null";
                $param->setType($union);
                if ($native !== 'mixed') {
                    $param->setDefaultValue(new Literal(
                        $namespace->simplifyName($omitted).'::Value'
                    ));
                } else {
                    $param->setDefaultValue(null);
                }
            } else {
                $param->setType($this->types->native($prop->type));
                if (! $prop->required || $prop->type->nullable) {
                    $param->setNullable();
                }
                if (! $prop->required) {
                    $param->setDefaultValue(null);
                }
            }

            $docType = $this->paramDocType($object, $prop);
            if ($prop->deprecated) {
                $param->addComment('@deprecated per the OpenAPI spec');
            }
            $line = "@param {$docType} \${$prop->phpName}";
            if ($prop->description !== null || $prop->wireName !== $prop->phpName) {
                $note = $prop->description ?? '';
                if ($prop->wireName !== $prop->phpName) {
                    $note = trim("[{$prop->wireName}] ".$note);
                }
                $line .= ' '.$note;
            }
            $constructor->addComment($line);
        }
    }

    private function paramDocType(ObjectDef $object, PropertyDef $prop): string
    {
        $doc = $this->types->doc($prop->type);

        if ($object->sentinelStyle && ! $prop->required) {
            return $doc === 'mixed' ? 'mixed' : $this->types->omittedClass()."|{$doc}|null";
        }

        if (($prop->type->nullable || ! $prop->required) && $doc !== 'mixed') {
            return $doc.'|null';
        }

        return $doc;
    }

    /** @return list<PropertyDef> required first, spec order otherwise */
    private function ordered(ObjectDef $object): array
    {
        $required = array_values(array_filter($object->properties, fn (PropertyDef $p): bool => $p->required));
        $optional = array_values(array_filter($object->properties, fn (PropertyDef $p): bool => ! $p->required));

        return [...$required, ...$optional];
    }

    private function addFromArray(PhpNamespace $namespace, ClassType $class, ObjectDef $object): void
    {
        $method = $class->addMethod('fromArray')->setStatic()->setReturnType('self');
        $method->addComment('@param array<array-key, mixed> $data');
        $method->addComment('');
        $method->addComment('@throws '.Types::simplify($namespace, '\\'.$this->namespace.'\\Exceptions\\UnexpectedResponseException').' when the payload does not match the spec');
        $method->addParameter('data')->setType('array');

        $lines = ['return new self('];
        foreach ($this->ordered($object) as $prop) {
            $src = '$data['.var_export($prop->wireName, true).']';
            $expr = $this->expressions->fromWire($src, $prop->type, $prop->required, $object->className.'.'.$prop->wireName);
            $lines[] = "    {$prop->phpName}: ".Types::simplify($namespace, $expr).',';
        }
        $lines[] = ');';

        $method->setBody(implode("\n", $lines));
    }

    private function addJsonSerialize(PhpNamespace $namespace, ClassType $class, ObjectDef $object): void
    {
        $method = $class->addMethod('jsonSerialize')->setReturnType('array');
        $method->addComment('@return array<string, mixed>');

        $lines = ['$out = [];', ''];

        foreach ($object->properties as $prop) {
            $access = '$this->'.$prop->phpName;
            $wire = var_export($prop->wireName, true);

            if ($object->sentinelStyle && ! $prop->required) {
                if ($prop->type->kind === TypeKind::Mixed) {
                    // mixed sentinel props degrade to always-send-if-not-null
                    $lines[] = "if ({$access} !== null) {";
                } else {
                    $omitted = $namespace->simplifyName($this->types->omittedClass());
                    $lines[] = "if (! {$access} instanceof {$omitted}) {";
                }
                $expr = $this->expressions->toWire($access, $prop->type, definitelySet: false);
                $lines[] = "    \$out[{$wire}] = {$expr};";
                $lines[] = '}';
            } elseif ($prop->required && ! $prop->type->nullable) {
                $expr = $this->expressions->toWire($access, $prop->type, definitelySet: true);
                $lines[] = "\$out[{$wire}] = {$expr};";
            } else {
                // Inside the !== null guard the value is narrowed to non-null.
                $expr = $this->expressions->toWire($access, $prop->type, definitelySet: true);
                $lines[] = "if ({$access} !== null) {";
                $lines[] = "    \$out[{$wire}] = {$expr};";
                $lines[] = '}';
            }
        }

        $lines[] = '';
        $lines[] = 'return $out;';

        $method->setBody(implode("\n", Types::simplifyLines($namespace, $lines)));
    }
}
