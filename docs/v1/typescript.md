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
of the interfaces that use them.

## Interfaces and abstract classes

A property typed as an interface (or an abstract class) names a contract, not a shape. Roma
looks for the concrete classes that satisfy it among the classes in the `discover`
directories, and types the property as the union of them — each emitted as its own
interface, sorted by name and emitted once however many properties reference it:

```php
class EpisodeResponse extends Response {
    public ?ThumbnailSource $thumbnail = null;

    public function __construct(public string $title) {}
}
```

With `RemoteImageThumbnail` and `GeneratedCoverThumbnail` implementing `ThumbnailSource`
somewhere under a scanned directory, that generates:

```typescript
export interface EpisodeResponseBody {
  title: string;
  thumbnail: GeneratedCoverThumbnail | RemoteImageThumbnail | null;
}

export interface GeneratedCoverThumbnail {
  prompt: string;
}

export interface RemoteImageThumbnail {
  url: string;
  width: number;
}
```

Nothing lists the implementations: write a third one, re-run the generator, and it joins
the union. An interface stays the open contract it is meant to be.

The trade is that Roma only knows what it scanned. An implementation living outside the
`discover` directories is not found, and so is not in the union; add its directory to the
list to bring it in. Abstract implementations are skipped — they are contracts too, not
values.

When nothing implements the contract, there is nothing to describe: it is emitted as an
empty interface — `export interface ThumbnailSource {}` — which the property is typed as.

This is a response-side feature. A request property cannot be typed as an interface or
abstract class: there is no class for the mapper to build, so mapping such a request throws.

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
