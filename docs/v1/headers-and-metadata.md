---
extends: _layouts.docs.roma
section: content
title: Headers & Metadata
package: roma
version: v1
weight: 4
group: Requests
---

# Headers & metadata

Roma maps request headers and the convenience surface of the Laravel request — the things
you'd normally reach into `$request` for — onto typed properties.

## Headers

Map a header with `#[Header]`, or use a pre-made shortcut like `#[ContentType]`:

```php
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Headers\ContentType;

readonly class ApiRequest {
    #[Header('X-API-Key')]
    public string $apiKey;

    #[ContentType]
    public string $contentType;
}
```

A header is found however either side capitalizes its name. Names are compared in lowercase
with underscores for hyphens, so `X-RateLimit-Remaining` matches `x-ratelimit-remaining`, but not
`X-Rate-Limit-Remaining`, and `ContentType` doesn't match `Content-Type`. Error keys use the
name as you wrote it, such as `header.X-RateLimit-Remaining`.

Avoid dots in header names, since web servers treat them differently. nginx drops such a header
by default. PHP-FPM and `php artisan serve` replace the dot with an underscore, so a header sent
as `X-Request.Id` is read there with `#[Header('X-Request-Id')]`. Swoole and RoadRunner keep the
dot. Roma compares a dot as a dot, so that a client can't reach `#[Header('X-Auth-User')]` by
sending `X.Auth.User` past a proxy that strips `X-Auth-User`.

`#[Header]` reads a header as `$request->header($name)` does, from `$request->headers`, so a
header that middleware sets, replaces or removes is what the property sees. A repeated header
gives its first value, and a header whose value is null is absent. Response classes in the
[HTTP client](/docs/roma/v1/http-client) differ: they join a repeated header with commas.

## Header value objects

Some headers carry structure, not just a string. Type a property as a header **value
object** and Roma parses the header for you. `Authorization` splits the header into its
scheme and credentials, and self-locates — no attribute needed:

```php
use BYanelli\Roma\Http\Values\Authorization;

readonly class ApiRequest {
    public Authorization $auth;
}
```

```php
$request->auth->scheme;       // AuthScheme::Bearer | Basic | Digest
$request->auth->credentials;  // the raw credentials after the scheme
$request->auth->isBearer();   // convenience check

// Basic credentials, base64-decoded and split — null if not Basic / malformed.
$request->auth->basic()?->username;
$request->auth->basic()?->password;
```

A malformed `Authorization` header is rejected with a clean, header-level validation error.

## Self-sourcing metadata enums

Some metadata has a small fixed set of values. Roma ships enums for these, and typing a
property as one is enough — the enum knows which request source it comes from:

```php
use BYanelli\Roma\Http\Enums\ContentType;
use BYanelli\Roma\Http\Enums\Method;
use BYanelli\Roma\Http\Enums\Scheme;

readonly class MetadataEnumRequest {
    public Method $method;           // Method::Get, Method::Post, Method::Query, ...
    public ContentType $contentType; // ContentType::Json, ... (params stripped before matching)
    public Scheme $scheme;           // Scheme::Http | Https
}
```

An explicit source attribute still wins over the inferred one, and a value outside the
enum's cases can be mapped as a plain string via the accessor/header attribute directly
(e.g. `#[ContentType] public string $contentType`).

## Accessor attributes

Roma wraps most of the Laravel request surface with accessor attributes:

```php
use BYanelli\Roma\Http\Attributes\Accessors\Ip;
use BYanelli\Roma\Http\Attributes\Accessors\Segments;
use BYanelli\Roma\Http\Attributes\Accessors\UserAgent;

readonly class RequestInfo {
    #[Ip]
    public string $ip;
    #[UserAgent]
    public string $userAgent;

    /** @var array<string> */
    #[Segments]
    public array $segments;
}
```

* **Booleans:** `#[Ajax]`, `#[Secure]`, `#[Pjax]`, `#[Prefetch]`, `#[IsJson]`, `#[ExpectsJson]`, `#[WantsJson]`
* **Strings:** `#[Method]`, `#[Ip]`, `#[UserAgent]`, `#[Url]`, `#[FullUrl]`, `#[RequestPath]`, `#[DecodedPath]`, `#[Root]`, `#[Host]`, `#[SchemeAndHttpHost]`, `#[BearerToken]`, `#[Format]`, `#[Content]`
* **Arrays:** `#[Ips]`, `#[Segments]`

`#[RequestPath]` is the request's path (`$request->path()`), reported as `request.path`;
`#[Path]` reads a route parameter.

Boolean accessors accept `mustBe` to become a constraint: `#[Secure(mustBe: true)]`
requires HTTPS, `#[Ajax(mustBe: false)]` requires a non-AJAX request.

## The raw body

`#[Content]` maps the request body exactly as it was sent. Use it when something is computed
over the raw bytes, such as a webhook signature. Re-encoding the parsed input would not
reproduce the original spacing or key order. The parsed fields can be mapped in the same
class:

```php
use BYanelli\Roma\Http\Attributes\Accessors\Content;

readonly class WebhookRequest {
    #[Content]
    public string $payload;   // the body, byte for byte

    public string $type;      // parsed from the same JSON body
}
```

