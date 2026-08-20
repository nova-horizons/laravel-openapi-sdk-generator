<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Ir;

final class ApiDef
{
    /**
     * @param  array<string, ObjectDef>  $objects  className => def
     * @param  array<string, EnumDef>  $enums  className => def
     * @param  array<string, list<OperationDef>>  $resources  resource short name (e.g. "Telemetry") => operations
     */
    public function __construct(
        public string $title,
        public string $version,
        public array $objects = [],
        public array $enums = [],
        public array $resources = [],
        /** Header name for apiKey auth (from securitySchemes, or config default). */
        public ?string $apiKeyHeader = null,
        /** DTO class name for error bodies, when every documented non-2xx body uses one schema. */
        public ?string $errorClass = null,
        /** Default base URL from spec servers[0], when trustworthy (absolute, non-localhost). */
        public ?string $serverUrl = null,
    ) {}

    public function needsOmitted(): bool
    {
        foreach ($this->objects as $object) {
            if ($object->sentinelStyle) {
                return true;
            }
        }

        return false;
    }
}
