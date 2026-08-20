<?php

// Laravel helper signatures for PHPStan. The real implementations come from
// laravel/framework in the host application; this package only requires
// illuminate/support + illuminate/console.

if (! function_exists('config')) {
    /**
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    function config($key = null, $default = null)
    {
        return $default;
    }
}

if (! function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return $path;
    }
}

if (! function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return $path;
    }
}

if (! function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return $path;
    }
}
