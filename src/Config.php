<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator;

final readonly class Config
{
    /** @param list<string> $allow */
    public function __construct(
        public string $specPath,
        public string $outputDir,
        /** Root namespace for generated code, e.g. "Gizmo\\Sdk". */
        public string $namespace,
        /** Client class short name, e.g. "GizmoClient". Defaults to "{Title}Client". */
        public ?string $clientClass = null,
        /** Header used when an apiKey is passed to Client::make() and the spec
         *  declares no apiKey security scheme. */
        public string $defaultApiKeyHeader = 'X-Api-Key',
        /** Config key the client's #[Config] attributes read, e.g. "services.orbit"
         *  => services.orbit.url / services.orbit.api_key. Defaults to
         *  "services.{snake(brand)}". */
        public ?string $configKey = null,
        /** Violation rules to tolerate (SpecViolation::* constants). Allowed
         *  violations downgrade to warnings; everything else fails generation. */
        public array $allow = [],
    ) {}
}
