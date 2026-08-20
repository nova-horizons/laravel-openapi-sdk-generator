<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator;

use Illuminate\Support\Str;

/**
 * All naming rules live here so they stay deterministic and testable.
 */
final class Names
{
    /**
     * Wire property name => PHP property name.
     *
     * Strips leading underscores, camelCases on underscore boundaries, and
     * preserves embedded capitalisation:
     *   __rec_SerialNumber   => recSerialNumber
     *   calc_TotalDueUSD     => calcTotalDueUSD
     *   business_name        => businessName
     */
    public static function property(string $wireName): string
    {
        $segments = array_values(array_filter(explode('_', ltrim($wireName, '_')), fn (string $s): bool => $s !== ''));

        if ($segments === []) {
            throw new \RuntimeException("Cannot derive a property name from '{$wireName}'");
        }

        $name = lcfirst(array_shift($segments));
        foreach ($segments as $segment) {
            $name .= ucfirst($segment);
        }

        $name = preg_replace('/[^A-Za-z0-9]/', '', $name) ?? $name;

        if ($name === '' || is_numeric($name[0])) {
            $name = 'field'.ucfirst($name);
        }

        return $name;
    }

    public static function className(string $raw): string
    {
        $name = Str::studly(preg_replace('/[^A-Za-z0-9_ \-]/', '', $raw) ?? $raw);

        if ($name === '' || is_numeric($name[0])) {
            $name = 'Schema'.$name;
        }

        return $name;
    }

    public static function method(string $operationId): string
    {
        return lcfirst(Str::studly(preg_replace('/[^A-Za-z0-9_ \-]/', '_', $operationId) ?? $operationId));
    }

    public static function resource(string $tag): string
    {
        return self::className($tag);
    }

    /** Client accessor method for a resource, e.g. "Widgets" => "widgets". */
    public static function accessor(string $resourceName): string
    {
        return lcfirst($resourceName);
    }

    /** Suggested .env variable prefix for a config key, e.g. "gizmo-api" => "GIZMO_API". */
    public static function envPrefix(string $configKeyTail): string
    {
        return strtoupper(str_replace('-', '_', Str::snake($configKeyTail)));
    }

    /** Enum case name from a raw value, e.g. "30-day" => "V30Day", "annual" => "Annual". */
    public static function enumCase(string|int $value): string
    {
        $name = Str::studly(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $value) ?? (string) $value);

        if ($name === '' || is_numeric($name[0])) {
            $name = 'V'.$name;
        }

        return $name;
    }
}
