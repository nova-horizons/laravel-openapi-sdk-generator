<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Emitter;

use NovaHorizons\SdkGenerator\Ir\TypeKind;
use NovaHorizons\SdkGenerator\Ir\TypeRef;

/**
 * TypeRef => PHP native type declarations and PHPStan doc types.
 *
 * All class references are emitted fully-qualified; nette's printer plus
 * per-file "use" statements shorten them.
 */
final readonly class Types
{
    public function __construct(private string $namespace) {}

    public function dtoClass(string $shortName): string
    {
        return '\\'.$this->namespace.'\\Dto\\'.$shortName;
    }

    public function enumClass(string $shortName): string
    {
        return '\\'.$this->namespace.'\\Enums\\'.$shortName;
    }

    public function omittedClass(): string
    {
        return '\\'.$this->namespace.'\\Omitted';
    }

    public function castClass(): string
    {
        return '\\'.$this->namespace.'\\Cast';
    }

    /** Native PHP type declaration (without nullability). */
    public function native(TypeRef $type): string
    {
        return match ($type->kind) {
            TypeKind::String => 'string',
            TypeKind::Int => 'int',
            TypeKind::Float => 'float',
            TypeKind::Bool => 'bool',
            TypeKind::Date, TypeKind::DateTime => '\\Illuminate\\Support\\Carbon',
            TypeKind::Mixed => 'mixed',
            TypeKind::ArrayOf, TypeKind::Map => 'array',
            TypeKind::Object => $this->dtoClass((string) $type->className),
            TypeKind::Enum => $this->enumClass((string) $type->className),
        };
    }

    /** PHPStan doc type. */
    public function doc(TypeRef $type): string
    {
        return match ($type->kind) {
            TypeKind::ArrayOf => 'list<'.$this->doc($type->items ?? TypeRef::mixed()).'>',
            TypeKind::Map => 'array<string, '.$this->mapValueDoc($type->items ?? TypeRef::mixed()).'>',
            default => $this->native($type),
        };
    }

    /**
     * Map value doc type. Hydration only supports scalar map values (via the
     * Cast helpers); anything else degrades to mixed so docs match runtime.
     */
    private function mapValueDoc(TypeRef $items): string
    {
        return match ($items->kind) {
            TypeKind::String, TypeKind::Int, TypeKind::Float, TypeKind::Bool => $this->native($items),
            default => 'mixed',
        };
    }

    /** True when the doc type says more than the native type (arrays). */
    public function needsDoc(TypeRef $type): bool
    {
        return in_array($type->kind, [TypeKind::ArrayOf, TypeKind::Map], true);
    }
}
