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
use BYanelli\Roma\Request\Attributes\Header;
use BYanelli\Roma\Request\Attributes\Headers\ContentType;

readonly class ApiRequest {
    #[Header('X-API-Key')]
    public string $apiKey;

    #[ContentType]
    public string $contentType;
}
```

## Header value objects

Some headers carry structure, not just a string. Type a property as a header **value
object** and Roma parses the header for you. `Authorization` splits the header into its
scheme and credentials, and self-locates — no attribute needed:

```php
use BYanelli\Roma\Request\Values\Authorization;

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
use BYanelli\Roma\Request\Enums\ContentType;
use BYanelli\Roma\Request\Enums\Method;
use BYanelli\Roma\Request\Enums\Scheme;

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
use BYanelli\Roma\Request\Attributes\Accessors\Ip;
use BYanelli\Roma\Request\Attributes\Accessors\Segments;
use BYanelli\Roma\Request\Attributes\Accessors\UserAgent;

readonly class RequestInfo {
    #[Ip]        public string $ip;
    #[UserAgent] public string $userAgent;

    /** @var array<string> */
    #[Segments]  public array $segments;
}
```

* **Booleans:** `#[Ajax]`, `#[Secure]`, `#[Pjax]`, `#[Prefetch]`, `#[IsJson]`, `#[ExpectsJson]`, `#[WantsJson]`
* **Strings:** `#[Method]`, `#[Ip]`, `#[UserAgent]`, `#[Url]`, `#[FullUrl]`, `#[Path]`, `#[DecodedPath]`, `#[Root]`, `#[Host]`, `#[SchemeAndHttpHost]`, `#[BearerToken]`, `#[Format]`
* **Arrays:** `#[Ips]`, `#[Segments]`

Every boolean accessor accepts `mustBe` to become a constraint: `#[Secure(mustBe: true)]`
requires HTTPS, `#[Ajax(mustBe: false)]` requires a non-AJAX request.
