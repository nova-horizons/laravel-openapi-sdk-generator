<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Ir;

final readonly class TypeRef
{
    public function __construct(
        public TypeKind $kind,
        /** Class short name for Object/Enum kinds. */
        public ?string $className = null,
        /** Element type for ArrayOf, value type for Map. */
        public ?TypeRef $items = null,
        public bool $nullable = false,
    ) {}

    public function with(bool $nullable): self
    {
        return new self($this->kind, $this->className, $this->items, $nullable);
    }

    public static function mixed(): self
    {
        return new self(TypeKind::Mixed, nullable: true);
    }
}
