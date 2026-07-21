---
extends: _layouts.docs.roma
section: content
title: Response Objects
package: roma
version: v1
weight: 1
group: Responses
---

# Response objects

Responses are the mirror of requests. Where a request property has a _source_ it's pulled
_from_, a response property has a _destination_ it's pushed _to_. By default that
destination is the JSON body; a property can instead be lifted to the status code or a
header.

## Define and return

Extend `Response`, declare typed public properties, and return the object. Roma serializes
it to a `JsonResponse`:

```php
use BYanelli\Roma\Response\Response;

class UserResponse extends Response {
    public function __construct(
        public string $name,
        public int $age,
    ) {}
}

class ShowUserController {
    public function __invoke(): UserResponse {
        return new UserResponse('Bill', 40);
    }
}
```

If a class already extends something else, use the traits directly: `IsResponsable` for a
full HTTP response, or `IsArrayable` alone for a nested value that only needs to serialize.

## Value conversion

Property values are converted to their JSON form on the way out, recursively — backed enums
become `{ name, value }`, unit enums their name, `DateTimeInterface` an ISO-8601 string,
nested response objects and arrays recurse element by element.

## Omit unset properties with `#[Optional]`

A response property has no implicit default: leaving it unset makes serialization throw,
surfacing a field you forgot to populate. Mark it `#[Optional]` to omit it when unset, or
give it an explicit default to serialize that value:

```php
use BYanelli\Roma\Response\Attributes\Optional;
use BYanelli\Roma\Response\Response;

class ContactResponse extends Response {
    public string $name;

    #[Optional]
    public ?string $nickname;    // omitted entirely when unset

    public ?string $note = null; // an explicit default serializes as null
}
```

## Status and headers

Mark an `int` property `#[Status]` to make its value the HTTP status code, or a property
`#[Header('Name')]` to emit it as a response header. Both are lifted out of the body:

```php
use BYanelli\Roma\Response\Attributes\Header;
use BYanelli\Roma\Response\Attributes\Status;
use BYanelli\Roma\Response\Response;

class CreatedResponse extends Response {
    public string $name = 'Bill';

    #[Status]
    public int $status = 201; // response is 201; body is {"name":"Bill"}

    #[Header('Cache-Control')]
    public string $cacheControl = 'max-age=3600';
}
```

Without a `#[Status]` property the response defaults to 200. Date formatting can be
customized per property with `#[DateFormat]`. For values computed at runtime, override the
`responseStatus()`, `responseHeaders()`, or `dateFormat()` methods.
