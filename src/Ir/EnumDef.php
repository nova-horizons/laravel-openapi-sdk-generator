<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Ir;

final readonly class EnumDef
{
    /** @param array<string, string|int> $cases case name => backing value */
    public function __construct(
        public string $className,
        public string $backingType, // 'string' or 'int'
        public array $cases,
        public ?string $description = null,
    ) {}
}
