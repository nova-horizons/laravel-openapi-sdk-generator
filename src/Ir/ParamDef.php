<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Ir;

final readonly class ParamDef
{
    public function __construct(
        public string $wireName,
        public string $phpName,
        public string $in, // 'path' | 'query'
        public TypeRef $type,
        public bool $required,
        public ?string $description = null,
        /** @var list<string|int>|null Allowed values (from inline enum), documented not enforced. */
        public ?array $allowedValues = null,
    ) {}
}
