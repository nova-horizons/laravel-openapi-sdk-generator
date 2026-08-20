<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Emitter;

use Nette\PhpGenerator\PhpFile;

/**
 * Emits the Cast support class: runtime narrowing from `mixed` wire values to
 * typed values, so hydration code passes PHPStan at the strictest levels
 * without unchecked casts. Every method takes the wire path of the value so
 * failures say exactly where the response diverged from the spec.
 */
final readonly class CastEmitter
{
    public function __construct(private string $namespace) {}

    public function emit(): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes();

        $namespace = $file->addNamespace($this->namespace);
        $namespace->addUse('Illuminate\\Support\\Carbon');
        $namespace->addUse($this->namespace.'\\Exceptions\\UnexpectedResponseException');

        $class = $namespace->addClass('Cast');
        $class->setFinal();
        $class->addComment("Runtime narrowing for wire values.\n");
        $class->addComment('@internal generated support code — throws UnexpectedResponseException when the API response does not match the OpenAPI spec.');

        // Coercion is deliberately narrow: legacy backends interchange numbers and
        // numeric strings, so those convert — anything lossy or non-numeric throws.
        $scalars = [
            'toString' => ['string', [
                'if (is_string($value)) {',
                '    return $value;',
                '}',
                '',
                'if (is_int($value) || is_float($value)) {',
                '    return (string) $value;',
                '}',
                '',
                "self::fail('string', \$value, \$path);",
            ]],
            'toInt' => ['int', [
                'if (is_int($value)) {',
                '    return $value;',
                '}',
                '',
                '// Integral numeric strings ("42", "42.0") coerce; "12.7" or "abc" throw.',
                'if (is_string($value) && is_numeric($value)) {',
                '    $int = (int) $value;',
                '    if ((string) $int === $value || (float) $value === (float) $int) {',
                '        return $int;',
                '    }',
                '}',
                '',
                'if (is_float($value) && $value === (float) (int) $value) {',
                '    return (int) $value;',
                '}',
                '',
                "self::fail('int', \$value, \$path);",
            ]],
            'toFloat' => ['float', [
                'if (is_float($value) || is_int($value)) {',
                '    return $value;',
                '}',
                '',
                'if (is_string($value) && is_numeric($value)) {',
                '    return (float) $value;',
                '}',
                '',
                "self::fail('float', \$value, \$path);",
            ]],
            'toBool' => ['bool', [
                'if (is_bool($value)) {',
                '    return $value;',
                '}',
                '',
                "if (\$value === 0 || \$value === '0' || \$value === 'false') {",
                '    return false;',
                '}',
                '',
                "if (\$value === 1 || \$value === '1' || \$value === 'true') {",
                '    return true;',
                '}',
                '',
                "self::fail('bool', \$value, \$path);",
            ]],
        ];

        foreach ($scalars as $name => [$type, $body]) {
            $method = $class->addMethod($name)->setStatic()->setReturnType($type);
            $method->addComment('@throws UnexpectedResponseException');
            $method->addParameter('value')->setType('mixed');
            $method->addParameter('path')->setType('string')->setDefaultValue('');
            $method->setBody(implode("\n", $body));

            $orNull = $class->addMethod($name.'OrNull')->setStatic()->setReturnType('?'.$type);
            $orNull->addComment('@throws UnexpectedResponseException');
            $orNull->addParameter('value')->setType('mixed');
            $orNull->addParameter('path')->setType('string')->setDefaultValue('');
            $orNull->setBody("return \$value === null ? null : self::{$name}(\$value, \$path);");
        }

        $date = $class->addMethod('toDate')->setStatic()->setReturnType('Illuminate\\Support\\Carbon');
        $date->addComment('@throws UnexpectedResponseException');
        $date->addParameter('value')->setType('mixed');
        $date->addParameter('path')->setType('string')->setDefaultValue('');
        $date->setBody(implode("\n", [
            '$string = self::toString($value, $path);',
            '',
            "// Carbon::parse() turns '' into now() and zero-dates into nonsense — both",
            '// are legacy-DB idioms for "no date", so they must fail, not fabricate.',
            "if (trim(\$string) === '' || str_starts_with(\$string, '0000-00-00')) {",
            "    self::fail('parseable date', \$value, \$path);",
            '}',
            '',
            'try {',
            '    return Carbon::parse($string);',
            '} catch (\\InvalidArgumentException) { // Carbon\'s InvalidFormatException extends this',
            "    self::fail('parseable date', \$value, \$path);",
            '}',
        ]));

        $dateOrNull = $class->addMethod('toDateOrNull')->setStatic()->setReturnType('?Illuminate\\Support\\Carbon');
        $dateOrNull->addComment('@throws UnexpectedResponseException');
        $dateOrNull->addParameter('value')->setType('mixed');
        $dateOrNull->addParameter('path')->setType('string')->setDefaultValue('');
        $dateOrNull->setBody('return $value === null ? null : self::toDate($value, $path);');

        $toArray = $class->addMethod('toArray')->setStatic()->setReturnType('array');
        $toArray->addComment('@return array<array-key, mixed>');
        $toArray->addComment('@throws UnexpectedResponseException');
        $toArray->addParameter('value')->setType('mixed');
        $toArray->addParameter('path')->setType('string')->setDefaultValue('');
        $toArray->setBody(implode("\n", [
            'if (is_array($value)) {',
            '    return $value;',
            '}',
            '',
            "self::fail('array', \$value, \$path);",
        ]));

        $toList = $class->addMethod('toList')->setStatic()->setReturnType('array');
        $toList->addComment('@return list<mixed>');
        $toList->addComment('@throws UnexpectedResponseException');
        $toList->addParameter('value')->setType('mixed');
        $toList->addParameter('path')->setType('string')->setDefaultValue('');
        $toList->setBody('return array_values(self::toArray($value, $path));');

        $enum = $class->addMethod('toEnum')->setStatic()->setReturnType('\\BackedEnum');
        $enum->addComment('@template T of \\BackedEnum');
        $enum->addComment('');
        $enum->addComment('@param class-string<T> $enumClass');
        $enum->addComment('@return T');
        $enum->addComment('@throws UnexpectedResponseException');
        $enum->addParameter('value')->setType('mixed');
        $enum->addParameter('enumClass')->setType('string');
        $enum->addParameter('path')->setType('string')->setDefaultValue('');
        $enum->setBody(implode("\n", [
            '// Compare stringified backing values so int-backed enums accept numeric',
            '// strings and string-backed enums accept ints — tryFrom() would TypeError.',
            '$string = self::toString($value, $path);',
            '',
            'foreach ($enumClass::cases() as $case) {',
            '    if ((string) $case->value === $string) {',
            '        return $case;',
            '    }',
            '}',
            '',
            "self::fail('one of ['.implode(', ', array_map(static fn (\\BackedEnum \$c): string => (string) \$c->value, \$enumClass::cases())).']', \$value, \$path);",
        ]));

        $maps = [
            'toMap' => ['mixed', '$item'],
            'toStringMap' => ['string', "self::toString(\$item, \$path.'['.\$key.']')"],
            'toIntMap' => ['int', "self::toInt(\$item, \$path.'['.\$key.']')"],
            'toFloatMap' => ['float', "self::toFloat(\$item, \$path.'['.\$key.']')"],
            'toBoolMap' => ['bool', "self::toBool(\$item, \$path.'['.\$key.']')"],
        ];

        foreach ($maps as $name => [$valueType, $expr]) {
            $method = $class->addMethod($name)->setStatic()->setReturnType('array');
            $method->addComment("@return array<string, {$valueType}>");
            $method->addComment('@throws UnexpectedResponseException');
            $method->addParameter('value')->setType('mixed');
            $method->addParameter('path')->setType('string')->setDefaultValue('');
            $method->setBody(implode("\n", [
                '$out = [];',
                'foreach (self::toArray($value, $path) as $key => $item) {',
                "    \$out[(string) \$key] = {$expr};",
                '}',
                '',
                'return $out;',
            ]));

            $orNull = $class->addMethod($name.'OrNull')->setStatic()->setReturnType('?array');
            $orNull->addComment("@return array<string, {$valueType}>|null");
            $orNull->addComment('@throws UnexpectedResponseException');
            $orNull->addParameter('value')->setType('mixed');
            $orNull->addParameter('path')->setType('string')->setDefaultValue('');
            $orNull->setBody("return \$value === null ? null : self::{$name}(\$value, \$path);");
        }

        $fail = $class->addMethod('fail')->setPrivate()->setStatic()->setReturnType('never');
        $fail->addComment('@throws UnexpectedResponseException');
        $fail->addParameter('expected')->setType('string');
        $fail->addParameter('value')->setType('mixed');
        $fail->addParameter('path')->setType('string');
        $fail->setBody(implode("\n", [
            'throw new UnexpectedResponseException(',
            "    (\$path === '' ? '' : \$path.': ').'expected '.\$expected.', got '.get_debug_type(\$value)",
            ');',
        ]));

        return $file;
    }
}
