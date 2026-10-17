<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Request Auto-Injection
    |--------------------------------------------------------------------------
    |
    | When enabled, any class carrying a class-level #[Request] attribute can be
    | resolved from the container by type-hint alone — the parameter-level
    | #[Request] hint becomes optional. Disable to require the explicit hint.
    |
    */

    'auto_inject' => true,

    /*
    |--------------------------------------------------------------------------
    | Precognition
    |--------------------------------------------------------------------------
    |
    | A precognitive request validates form data on behalf of a front-end
    | form, so its validation errors are keyed by the bare field name the
    | client posted ("email", not "input.email") — the shape the official
    | laravel-precognition-* helpers map onto form fields. Ordinary requests
    | are unaffected and keep Roma's source-prefixed error keys. Enable
    | source_prefixed_errors to key precognitive errors by their full
    | source-prefixed names as well.
    |
    */

    'precognition' => [
        'source_prefixed_errors' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    |
    | Directories scanned for Roma classes: the requests and responses that
    | `roma:typescript` generates, the members of interfaces and abstract
    | classes with #[AutoDiscriminated], and the config objects that
    | `roma:config:validate` checks. `php artisan roma:cache`, which
    | `php artisan optimize` runs, saves the members and config objects to
    | bootstrap/cache/roma.php. While that file exists it is the only source,
    | so run it again after adding a member class or a config object.
    |
    */

    'discover' => [
        app_path(),
    ],

    /*
    |--------------------------------------------------------------------------
    | TypeScript Generation
    |--------------------------------------------------------------------------
    |
    | The `roma:typescript` command generates TypeScript definitions for Roma
    | request and response classes. Each request produces up to three
    | interfaces — {Name}Body, {Name}Query and {Name}Headers — keyed by the wire
    | keys the endpoint accepts; each response produces one interface keyed by
    | the serialized output keys.
    |
    | Classes are auto-detected by scanning the `discover` directories: a
    | request is any class marked with a class-level #[Request] attribute, a
    | response is any class extending Response or using the IsResponsable trait.
    | The `requests` and `responses` lists add classes that are outside those
    | directories.
    |
    */

    'typescript' => [

        // Where the generated .d.ts file is written.
        'output' => resource_path('js/roma.d.ts'),

        // Additional request classes to generate interfaces for.
        'requests' => [
            // App\Http\Requests\CreateUserRequest::class,
        ],

        // Additional response classes to generate interfaces for.
        'responses' => [
            // App\Http\Responses\UserResponse::class,
        ],

    ],

];
