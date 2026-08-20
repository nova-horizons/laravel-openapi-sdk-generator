<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Ir;

final readonly class OperationDef
{
    /** Wire names of the query params that drive lazy pagination. */
    public const PAGING_PARAMS = ['offset', 'limit'];

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

    /** GET returning a list of DTOs, with int offset+limit query params — gets a lazy auto-paging variant. */
    public function isPaginated(): bool
    {
        if ($this->httpMethod !== 'get'
            || $this->returnType?->kind !== TypeKind::ArrayOf
            || ($this->returnType->items ?? null)?->kind !== TypeKind::Object) {
            return false;
        }

        $found = 0;
        foreach ($this->params as $param) {
            if ($param->in === 'query'
                && in_array($param->wireName, self::PAGING_PARAMS, true)
                && $param->type->kind === TypeKind::Int) {
                $found++;
            }
        }

        return $found === count(self::PAGING_PARAMS);
    }
}
