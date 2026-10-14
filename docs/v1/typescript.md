---
extends: _layouts.docs.roma
section: content
title: TypeScript Generation
package: roma
version: v1
weight: 1
group: TypeScript
---

# TypeScript generation

Roma generates TypeScript definitions for your request and response objects, so the
frontend and backend share one source of truth. Run:

```bash
php artisan roma:typescript
```

It writes a `.d.ts` file (default `resources/js/roma.d.ts`, overridable with `--output` or
config) containing an interface for every request and response.

## What gets generated

A request is split into up to three interfaces — one per HTTP location its properties come
from — named `{Name}Body`, `{Name}Query`, and `{Name}Headers`; empty ones are dropped. A
response produces a `{Name}Body`, plus a `{Name}Headers` when it emits `#[Header]`s. Fields
are keyed by their **wire key** (the source key, or a `#[Key]`/header name), and optional
properties get a `?`.

```php
#[Request]
readonly class SearchRequest {
    public function __construct(
        public string $note,                          // default (input) -> Body
        #[Query] public int $page = 1,                // -> Query (optional)
        #[Header('X-Api-Key')] public string $apiKey, // -> Headers
    ) {}
}
```

generates:

```typescript
export interface SearchRequestBody {
  note: string;
}

export interface SearchRequestHeaders {
  'X-Api-Key': string;
}

export interface SearchRequestQuery {
  page?: number;
}
```

Enums become a named `const` of `{ name, value }` objects plus a union type, emitted ahead
of the interfaces that use them. A map such as `array<string, T>` becomes `Record<string, T>`
(`Record<number, T>` for `int` keys), and an array shape becomes an inline object type such
as `{ input: number; output?: number }`.

## Interfaces and abstract classes

A property typed as an interface or an abstract class has no single shape to describe, so
generation throws for it. Type the property with a concrete class.

## Auto-detection

Classes are discovered by scanning the directories in `roma.typescript.discover` (default
`app/`) — there is no list to maintain by hand:

* a **request** is any class marked with a class-level `#[Request]` attribute;
* a **response** is any class extending `Response` or using the `IsResponsable` trait.

The `requests` and `responses` config lists are an additive escape hatch for classes
outside the scanned directories.

## Renaming a type

A generated type takes its short class name by default. Override it with `#[TypeScriptName]`
when the short name would collide. An `#[Input]` property defaults to the `Body` interface
(it reads from both body and query); force it into `Query` with
`#[InputMapsToTypeScriptQuery]`.
