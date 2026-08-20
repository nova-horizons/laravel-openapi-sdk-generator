<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator;

use cebe\openapi\Reader;
use Symfony\Component\Yaml\Yaml;

final readonly class LoadedSpec
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public array $raw,
        /** md5 of the raw spec bytes, for stamping generated files. */
        public string $hash,
    ) {}
}

final class SpecLoader
{
    /**
     * Loads without resolving references so that $ref names (which drive DTO
     * class names) are preserved. The Mapper resolves refs by pointer.
     */
    public static function load(string $path): LoadedSpec
    {
        $real = realpath($path);
        if ($real === false) {
            throw new \RuntimeException("Spec file not found: {$path}");
        }

        $contents = file_get_contents($real);
        if ($contents === false) {
            throw new \RuntimeException("Could not read spec file: {$real}");
        }

        if (str_ends_with(strtolower($real), '.json')) {
            $openApi = Reader::readFromJson($contents);
            $raw = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } else {
            $openApi = Reader::readFromYaml($contents);
            $raw = Yaml::parse($contents);
        }

        if (! is_array($raw)) {
            throw new \RuntimeException('Spec did not parse to an array');
        }

        if (! $openApi->validate()) {
            $errors = implode("\n  - ", $openApi->getErrors());
            throw new \RuntimeException("Spec failed validation:\n  - {$errors}");
        }

        return new LoadedSpec($raw, md5($contents));
    }
}
