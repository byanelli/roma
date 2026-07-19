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
    | The `requests` and `responses` lists add classes that live outside those
    | directories.
    |
    */

    'typescript' => [

        // Where the generated .d.ts file is written.
        'output' => resource_path('js/roma.d.ts'),

        // Directories scanned to auto-detect request and response classes.
        'discover' => [
            app_path(),
        ],

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
