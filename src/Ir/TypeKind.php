<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Ir;

enum TypeKind
{
    case String;
    case Int;
    case Float;
    case Bool;
    case Date;
    case DateTime;
    case Mixed;
    case ArrayOf;
    case Map;
    case Object;
    case Enum;
}
