<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Emitter;

use NovaHorizons\SdkGenerator\Ir\TypeKind;
use NovaHorizons\SdkGenerator\Ir\TypeRef;

/**
 * Builds PHP expressions for hydrating (wire => PHP) and serialising
 * (PHP => wire) values.
 *
 * Design notes:
 * - PHPStan level 9/10: wire values are `mixed` and may not be cast or passed
 *   to typed parameters, so conversions route through the generated Cast
 *   helpers, which narrow with runtime checks.
 * - Every Cast call carries the wire path (e.g. "Beacon.fixes[]") so
 *   spec-drift failures say exactly where.
 */
final readonly class Expressions
{
    public function __construct(private Types $types) {}

    /**
     * Expression that hydrates $src (a mixed array read) into the property type.
     *
     * @param  string  $path  wire path for error context, e.g. "Beacon.callsign"
     */
    public function fromWire(string $src, TypeRef $type, bool $required, string $path): string
    {
        $orNull = ! $required || $type->nullable;

        return match ($type->kind) {
            TypeKind::Mixed => "{$src} ?? null",
            TypeKind::Object, TypeKind::Enum, TypeKind::ArrayOf => $orNull
                ? 'isset('.$src.') ? '.$this->hydrate($src, $type, $path).' : null'
                : $this->hydrate("{$src} ?? null", $type, $path),
            default => $this->cast($this->castMethod($type).($orNull ? 'OrNull' : ''), "{$src} ?? null", $path),
        };
    }

    private function cast(string $method, string $args, string $path): string
    {
        return $this->types->castClass()."::{$method}({$args}, ".var_export($path, true).')';
    }

    private function castMethod(TypeRef $type): string
    {
        return match ($type->kind) {
            TypeKind::String => 'toString',
            TypeKind::Int => 'toInt',
            TypeKind::Float => 'toFloat',
            TypeKind::Bool => 'toBool',
            TypeKind::Date, TypeKind::DateTime => 'toDate',
            TypeKind::Map => $this->mapCastMethod($type->items ?? TypeRef::mixed()),
            default => throw new \LogicException('No cast method for '.$type->kind->name),
        };
    }

    private function mapCastMethod(TypeRef $items): string
    {
        return match ($items->kind) {
            TypeKind::Int => 'toIntMap',
            TypeKind::Float => 'toFloatMap',
            TypeKind::Bool => 'toBoolMap',
            TypeKind::String => 'toStringMap',
            default => 'toMap',
        };
    }

    private function hydrate(string $src, TypeRef $type, string $path): string
    {
        return match ($type->kind) {
            TypeKind::Object => $this->types->dtoClass((string) $type->className).'::fromArray('.$this->cast('toArray', $src, $path).')',
            TypeKind::Enum => $this->enumCast($src, (string) $type->className, $path),
            TypeKind::ArrayOf => $this->hydrateList($src, $type->items ?? TypeRef::mixed(), $path),
            default => throw new \LogicException('hydrate() only handles composite kinds'),
        };
    }

    private function enumCast(string $src, string $className, string $path): string
    {
        return $this->cast('toEnum', "{$src}, ".$this->types->enumClass($className).'::class', $path);
    }

    private function hydrateList(string $src, TypeRef $items, string $path): string
    {
        $list = $this->cast('toList', $src, $path);
        $itemPath = $path.'[]';

        return match ($items->kind) {
            TypeKind::Mixed => $list,
            TypeKind::Object => sprintf(
                'array_map(static fn (mixed $item): %s => %s::fromArray(%s), %s)',
                $this->types->dtoClass((string) $items->className),
                $this->types->dtoClass((string) $items->className),
                $this->cast('toArray', '$item', $itemPath),
                $list,
            ),
            TypeKind::Enum => sprintf(
                'array_map(static fn (mixed $value): %s => %s, %s)',
                $this->types->enumClass((string) $items->className),
                $this->enumCast('$value', (string) $items->className, $itemPath),
                $list,
            ),
            default => sprintf(
                'array_map(static fn (mixed $value): %s => %s, %s)',
                $this->types->native($items),
                $this->cast($this->castMethod($items), '$value', $itemPath),
                $list,
            ),
        };
    }

    /**
     * Expression that serialises a property access (e.g. "$this->x") to a wire
     * value. json_encode() handles nested JsonSerializable DTOs and BackedEnums,
     * so only dates need explicit formatting.
     *
     * @param  bool  $definitelySet  when false the value may be null and date
     *                               formatting must null-propagate
     */
    public function toWire(string $access, TypeRef $type, bool $definitelySet): string
    {
        $op = $definitelySet ? '->' : '?->';
        $carbon = '\\Illuminate\\Support\\Carbon';

        return match ($type->kind) {
            TypeKind::Date => "{$access}{$op}format('Y-m-d')",
            TypeKind::DateTime => "{$access}{$op}toIso8601String()",
            TypeKind::ArrayOf => match (($type->items ?? TypeRef::mixed())->kind) {
                TypeKind::Date => sprintf(
                    "array_map(static fn (%s \$value): string => \$value->format('Y-m-d'), %s)",
                    $carbon,
                    $access,
                ),
                TypeKind::DateTime => sprintf(
                    'array_map(static fn (%s $value): string => $value->toIso8601String(), %s)',
                    $carbon,
                    $access,
                ),
                default => $access,
            },
            default => $access,
        };
    }
}
