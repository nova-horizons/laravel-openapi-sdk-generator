<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API id
    |--------------------------------------------------------------------------
    | This API's handle. Consumers file their SDK config under it in their
    | composer.json "extra.sdk-generator" block, so one consumer can hold
    | SDKs for several APIs.
    */

    'id' => null,   // e.g. 'orbit'

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
    | Each entry points at a consumer checkout that receives generated SDK
    | code: 'key' => path to the checkout root (absolute, or relative to this
    | project's root). Machine-specific paths belong in .env, never here:
    |
    |   'space-app' => env('SDK_CONSUMER_SPACE_APP'),
    |
    |   # .env
    |   SDK_CONSUMER_SPACE_APP=../space-app
    |
    | Everything that defines the SDK lives with the consumer, committed in
    | its composer.json "extra" block, keyed by this API's 'id' (above):
    |
    |   "extra": {
    |       "sdk-generator": {
    |           "orbit": {
    |               "namespace": "App\\Sdk\\Orbit",     // required
    |               "out": "app/Sdk/Orbit",             // required, relative to the consumer root
    |               "client": "OrbitClient",            // optional, defaults to "{Title}Client"
    |               "config-key": "services.orbit",     // optional
    |               "allow": ["missing-error-schema"],  // optional, violation rules to tolerate
    |               "format": "vendor/bin/pint {out}"   // optional shell command run from the consumer
    |           }                                       // root after generation ({out} = the out path);
    |       }                                           // e.g. "vendor/bin/php-cs-fixer fix {out}" or
    |   }                                               // "vendor/bin/sail bin pint {out}". Default:
    |                                                   // the consumer's Pint when present; false
    |                                                   // disables the format step.
    */

    'consumers' => [
        //
    ],
];
