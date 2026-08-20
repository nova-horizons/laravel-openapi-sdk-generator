<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Ir;

final readonly class PropertyDef
{
    public function __construct(
        public string $wireName,
        public string $phpName,
        public TypeRef $type,
        public bool $required,
        public ?string $description = null,
        /** Example value from the spec, used by generated test fakes. */
        public mixed $example = null,
        public bool $deprecated = false,
    ) {}
}
