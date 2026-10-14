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
    public bool $isGift;                   // "yes" -> true
    public \DateTimeInterface $deliverBy;  // "2024-01-01" -> DateTime
    public Status $status;                 // "complete" -> Status::Complete (string enum)

    /** @var array<int> */
    public array $itemIds;                 // ["1","2"] -> [1, 2]
}
```

A `bool` accepts `true`, `1`, `"true"`, `"1"`, `"yes"` and `"on"` as true, and `false`, `0`,
`"false"`, `"0"`, `"no"` and `"off"` as false, ignoring case. Any other value, including an
empty string such as `?flag=`, fails validation with Laravel's boolean message.

An `array` property or constructor parameter needs its element type in PHPDoc: `@var` on a
property, `@param` on a constructor parameter. Roma accepts `array<T>` and `list<T>` (and
`non-empty-array<T>` / `non-empty-list<T>`), where `T` is a scalar, `mixed`, an enum, a
class, `self`, or another array type. A `list<T>` also validates that the input keys are
sequential (Laravel's `list` rule).

Roma doesn't read `use` imports, so a class in PHPDoc must be fully qualified
(`list<\App\Billing\Card>`) or in the declaring class's namespace (`list<Card>`). Any other
short name throws when the class definition is built, in mapping, serialization and
TypeScript generation. Pint's Laravel preset imports a fully qualified name in a docblock and
shortens it; to keep the name, configure its `fully_qualified_strict_types` fixer in
`pint.json`:

```json
{
    "preset": "laravel",
    "rules": {
        "fully_qualified_strict_types": { "import_symbols": true, "phpdoc_tags": [] }
    }
}
```

Maps and array shapes are supported too, and the property receives a plain array:

```php
class PricingRequest {
    /** @var array<string, int> */
    public array $limits;                  // ["daily" => "10"] -> ["daily" => 10]

    /** @var array{min: int, max?: int} */
    public array $range;                   // "min" is required, "max" may be omitted

    /** @var array<string, array<string, array{input: float, output: float}>> */
    public array $prices;
}
```

A map's key type is `string` or `int`; `array<int, T>` rejects input with non-integer keys.
Each value is coerced and validated, and errors are keyed by the full path, e.g.
`input.prices.gemini.flash.input`. A shape's keys are required unless marked `?`, but only
when the shape itself is present, the same as a nested object's properties.
