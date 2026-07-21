---
extends: _layouts.docs.roma
section: content
title: Installation
package: roma
version: v1
weight: 2
group: Getting Started
---

# Installation

Install Roma via Composer:

```bash
composer require byanelli/roma
```

Roma registers its service provider automatically. That's enough to start defining request
and response objects.

## Configuration

Publish the config file if you want to tune auto-injection, the TypeScript output, or
Precognition behaviour:

```bash
php artisan vendor:publish --tag=roma-config
```

This writes `config/roma.php`:

```php
return [
    // Inject a request object by type-hint alone, without the parameter-level
    // #[Request] attribute. See "Request objects".
    'auto_inject' => true,

    'typescript' => [
        // Where the generated .d.ts file is written.
        'output' => resource_path('js/roma.d.ts'),

        // Directories scanned to auto-detect request and response classes.
        'discover' => [app_path()],

        // Additional classes to include beyond what discovery finds.
        'requests' => [],
        'responses' => [],
    ],

    'precognition' => [
        // Keep Roma's source-prefixed error keys under Precognition, instead of
        // the bare field names front-end helpers expect.
        'source_prefixed_errors' => false,
    ],
];
```

Each setting is covered in the section it relates to.
