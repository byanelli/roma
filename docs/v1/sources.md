---
extends: _layouts.docs.roma
section: content
title: Sources
package: roma
version: v1
weight: 3
group: Requests
---

# Sources

Every property is populated from a **source** — a part of the request. By default a
property reads from the merged input bag (query string + body). Attributes bind it to a
specific source instead.

## Query and body

Use `#[Query]` or `#[Body]` to pin a property to one bag — handy when the same key can
appear in both:

```php
use BYanelli\Roma\Request\Attributes\Body;
use BYanelli\Roma\Request\Attributes\Query;

readonly class SearchRequest {
    #[Query]
    public int $page;     // always from the query string

    #[Body]
    public string $token; // always from the request body
}
```

### The QUERY method

`QUERY` ([RFC 10008](https://www.rfc-editor.org/info/rfc10008/)) is a safe, idempotent
method that carries its input in the request content instead of the URI — "GET with a
body". Roma treats that content as the body, so `#[Body]` and the default input source
both read it, and `#[Query]` still means the query string:

```php
use BYanelli\Roma\Request\Attributes\Query;
use BYanelli\Roma\Request\Enums\Method;

// QUERY /search?page=3  with  {"term": "roma"}
readonly class SearchRequest {
    public string $term;  // from the QUERY body

    #[Query]
    public int $page;     // from the query string

    public Method $method; // Method::Query
}
```

Laravel's `Route::any()` predates the method, so register a QUERY route explicitly:

```php
Route::match(['QUERY'], '/search', SearchController::class);
```

## Route parameters

Bind to a route parameter with `#[RouteParameter]`. The property name is the parameter name
unless you pass an explicit one. Route parameters arrive as strings, so scalar and enum
coercion applies:

```php
use BYanelli\Roma\Request\Attributes\RouteParameter;

// Route: /users/{id}/posts/{post_slug}
readonly class ShowPostRequest {
    #[RouteParameter]
    public int $id;      // from {id}, coerced "42" -> 42

    #[RouteParameter('post_slug')]
    public string $slug; // from {post_slug}
}
```

If the request has no bound route, a required route parameter fails validation with a
`route.` error — it never crashes.

## Cookies

Bind to a cookie with `#[Cookie]`, using the property name or an explicit cookie name.
Cookie names may contain literal dots, so pass one explicitly when the name isn't a valid
PHP property name:

```php
use BYanelli\Roma\Request\Attributes\Cookie;

readonly class PreferencesRequest {
    #[Cookie]
    public bool $darkMode;   // from the "darkMode" cookie

    #[Cookie('my.pref')]
    public string $pref;     // from the "my.pref" cookie
}
```

## Files

Type-hint a property as `Illuminate\Http\UploadedFile` and the upload is mapped:

```php
use Illuminate\Http\UploadedFile;

class FileRequest {
    public UploadedFile $myFile;
}
```

File uploads must be declared on the top-level request class — a `UploadedFile` inside a
nested object is not supported and throws.

## Type coercion and enums

Roma coerces string input to the property's declared type, and maps values onto
string-backed, integer-backed, and unit enums automatically:

```php
class OrderRequest {
    public float $price;                   // "9.99" -> 9.99
    public bool $isGift;                   // "true" -> true
    public \DateTimeInterface $deliverBy;  // "2024-01-01" -> DateTime
    public Status $status;                 // "complete" -> Status::Complete (string enum)

    /** @var array<int> */
    public array $itemIds;                 // ["1","2"] -> [1, 2]
}
```
