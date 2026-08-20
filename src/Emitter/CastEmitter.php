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

        $scalars = [
            'toString' => ['string', 'is_string($value)', '(string) $value'],
            'toInt' => ['int', 'is_int($value)', '(int) $value'],
            'toFloat' => ['float', 'is_float($value) || is_int($value)', '(float) $value'],
            'toBool' => ['bool', 'is_bool($value)', '(bool) $value'],
        ];

        foreach ($scalars as $name => [$type, $fastPath, $coerce]) {
            $method = $class->addMethod($name)->setStatic()->setReturnType($type);
            $method->addComment('@throws UnexpectedResponseException');
            $method->addParameter('value')->setType('mixed');
            $method->addParameter('path')->setType('string')->setDefaultValue('');
            $method->setBody(implode("\n", [
                "if ({$fastPath}) {",
                '    return $value;',
                '}',
                '',
                'if (is_scalar($value)) {',
                "    return {$coerce};",
                '}',
                '',
                "self::fail('{$type}', \$value, \$path);",
            ]));

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
            'try {',
            '    return Carbon::parse(self::toString($value, $path));',
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
            '$backing = is_int($value) ? $value : self::toString($value, $path);',
            '$case = $enumClass::tryFrom($backing);',
            '',
            'if ($case === null) {',
            "    self::fail('one of ['.implode(', ', array_map(static fn (\\BackedEnum \$c): string => (string) \$c->value, \$enumClass::cases())).']', \$value, \$path);",
            '}',
            '',
            'return $case;',
        ]));

        $maps = [
            'toMap' => ['mixed', '$item'],
            'toStringMap' => ['string', 'self::toString($item, $path)'],
            'toIntMap' => ['int', 'self::toInt($item, $path)'],
            'toFloatMap' => ['float', 'self::toFloat($item, $path)'],
            'toBoolMap' => ['bool', 'self::toBool($item, $path)'],
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
