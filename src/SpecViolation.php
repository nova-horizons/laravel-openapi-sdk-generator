<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator;

/**
 * A spec-quality problem that degrades the generated SDK. Generation fails on
 * any violation unless its rule is explicitly allowed — leniency is opt-in.
 */
final readonly class SpecViolation
{
    public const UNTYPED_RESPONSE = 'untyped-response';

    public const MISSING_ERROR_SCHEMA = 'missing-error-schema';

    public const ONE_OF_ANY_OF = 'oneof-anyof';

    public const NON_JSON_BODY = 'non-json-body';

    public const SKIPPED_PARAM = 'skipped-param';

    public const MISSING_OPERATION_ID = 'missing-operation-id';

    public function __construct(
        public string $rule,
        public string $location,
        public string $message,
        public string $fix,
    ) {}

    public function render(): string
    {
        return "[{$this->rule}] {$this->location}: {$this->message}\n    fix: {$this->fix}";
    }
}

final class SpecViolationsException extends \RuntimeException
{
    /** @param list<SpecViolation> $violations */
    public function __construct(public readonly array $violations)
    {
        $rules = array_unique(array_map(fn (SpecViolation $v): string => $v->rule, $violations));

        parent::__construct(
            'Spec failed strict validation with '.count($violations)." violation(s):\n\n"
            .implode("\n\n", array_map(fn (SpecViolation $v): string => $v->render(), $violations))
            ."\n\nFix the spec (preferred), or allow specific rules with --allow=".implode(',', $rules)
        );
    }
}
