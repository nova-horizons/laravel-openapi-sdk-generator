<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAPI spec
    |--------------------------------------------------------------------------
    | Path to the spec this project produces. With L5-Swagger this is the
    | generated api-docs file. Run `php artisan l5-swagger:generate` first
    | (or let sdk:generate do it via 'pregenerate').
    */

    'spec' => storage_path('api-docs/api-docs.json'),

    /*
    |--------------------------------------------------------------------------
    | Pre-generation hook
    |--------------------------------------------------------------------------
    | Artisan command to run before generating, e.g. 'l5-swagger:generate'.
    | Set to null to skip.
    */

    'pregenerate' => 'l5-swagger:generate',

    /*
    |--------------------------------------------------------------------------
    | Consumers
    |--------------------------------------------------------------------------
    | Each entry is a project that receives generated SDK code.
    |
    |   'billing' => [
    |       'path' => '/path/to/consumer/app/Sdk/Orbit',
    |       'namespace' => 'App\\Sdk\\Orbit',
    |       'client' => 'OrbitClient',    // optional, defaults to "{Title}Client"
    |       'pint' => true,               // run the consumer's Pint on output
    |   ],
    */

    'consumers' => [
        //
    ],
];
