<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Ir;

final class ObjectDef
{
    /** @param list<PropertyDef> $properties */
    public function __construct(
        public string $className,
        public array $properties,
        public ?string $description = null,
        /** All-optional request-body DTO: uses the Omitted sentinel so PATCH-style
         *  updates can distinguish "not provided" from "explicitly null". */
        public bool $sentinelStyle = false,
        public bool $deprecated = false,
    ) {}
}
