<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Ir;

final readonly class OperationDef
{
    /** @param list<ParamDef> $params */
    public function __construct(
        public string $operationId,
        public bool $deprecated,
        public string $methodName,
        public string $httpMethod, // get|post|put|patch|delete
        public string $path,
        public array $params,
        public ?TypeRef $bodyType,
        public bool $bodyRequired,
        public ?TypeRef $returnType,
        public ?string $summary = null,
    ) {}
}
