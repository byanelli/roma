---
extends: _layouts.docs.roma
section: content
title: Precognition
package: roma
version: v1
weight: 6
group: Requests
---

# Laravel Precognition

Roma request objects work with [Laravel Precognition](https://laravel.com/docs/precognition)
out of the box. Add the framework's `HandlePrecognitiveRequests` middleware to the route,
and a request carrying the `Precognition: true` header is validated without running your
controller:

```php
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;

Route::post('/signup', SignupController::class)
    ->middleware(HandlePrecognitiveRequests::class);
```

## What gets validated

Precognition is a front-end form concern, so a precognitive request validates **form data
only** — the `input`, `query`, `body`, and `file` sources. Rules for headers, cookies,
route parameters, and request metadata are skipped, and because those values go
unvalidated, the request object is never constructed and `#[Guard]` methods never run.
Everything runs as normal on the real submission.

## Responses and error keys

A failing precognitive request returns the usual `422`, with its form-data errors keyed by
the **bare field name** the client posted (`email`, `address.city`) rather than Roma's
usual [source-prefixed keys](/docs/roma/v1/validation) — so the official
`laravel-precognition-*` front-end helpers map them onto form fields without translation.
Set `roma.precognition.source_prefixed_errors` to `true` to keep the prefixed keys
(`input.email`) under Precognition too.

A passing precognitive request returns an empty `204` with a `Precognition-Success: true`
header.

## Validate-only

When the client narrows validation with a `Precognition-Validate-Only` header — as the
official helpers do on every keystroke — Roma validates only the matching fields. A pattern
matches a field by either name the client might know it by: the bare posted name (`email`,
`items.0.code`) or Roma's source-prefixed key (`input.email`). Fields outside the filter
may be missing or invalid; the request still succeeds if the named fields pass.
