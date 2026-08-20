<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Emitter;

use Nette\PhpGenerator\PhpFile;
use NovaHorizons\SdkGenerator\Ir\EnumDef;

final readonly class EnumEmitter
{
    public function __construct(private string $namespace) {}

    public function emit(EnumDef $enum): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes();

        $namespace = $file->addNamespace($this->namespace.'\\Enums');
        $type = $namespace->addEnum($enum->className);
        $type->setType($enum->backingType);

        if ($enum->description !== null) {
            $type->addComment($enum->description);
        }

        foreach ($enum->cases as $name => $value) {
            $type->addCase($name, $value);
        }

        return $file;
    }
}
