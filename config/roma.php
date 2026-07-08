<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TypeScript Generation
    |--------------------------------------------------------------------------
    |
    | The `roma:typescript` command generates TypeScript definitions for the
    | request and response classes listed here. Each request produces up to
    | three interfaces — {Name}Body, {Name}Query and {Name}Headers — keyed by
    | the wire keys the endpoint accepts; each response produces one interface
    | keyed by the serialized output keys.
    |
    */

    'typescript' => [

        // Where the generated .d.ts file is written.
        'output' => resource_path('js/roma.d.ts'),

        // Request classes to generate interfaces for.
        'requests' => [
            // App\Http\Requests\CreateUserRequest::class,
        ],

        // Response classes to generate interfaces for.
        'responses' => [
            // App\Http\Responses\UserResponse::class,
        ],

    ],

];
