<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Emitter;

use Nette\PhpGenerator\PhpFile;

final readonly class OmittedEmitter
{
    public function __construct(private string $namespace) {}

    public function emit(): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes();

        $namespace = $file->addNamespace($this->namespace);
        $enum = $namespace->addEnum('Omitted');
        $enum->addComment(
            "Sentinel for \"field not provided\" in partial-update request DTOs.\n\n".
            "Distinguishes omitting a field entirely (server leaves it untouched)\n".
            'from sending an explicit null (server clears it).'
        );
        $enum->addCase('Value');

        return $file;
    }
}
