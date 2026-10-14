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
nested objects and arrays recurse element by element.

A nested object doesn't need the `IsArrayable` trait: a plain class with public properties
is serialized the same way. A third-party `Arrayable` is converted with its own `toArray()`,
a `JsonSerializable` object is left for `json_encode`, and any other `Stringable` object
becomes its string.

Conversion works on the value, not the declared type, so a property typed as an interface
or an abstract class serializes whatever concrete object it holds, exactly as a nested
response object does. When the property is a
[discriminated union](/docs/roma/v1/nested-objects#discriminated-unions), or an array, map or
shape of one, the output starts with the discriminator: `{"type": "Card", "last4": "4242"}`.
The same object serialized directly, or through a property typed as `Card`, has no `type`
key. The value must be one of the union's classes.

`Response` implements `JsonSerializable`, and `IsArrayable` provides `jsonSerialize()`, so
`json_encode($response)` produces the same output as `toArray()`.

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

Without a `#[Status]` property the response defaults to 200. For values computed at
runtime, override the public `responseStatus()` or `responseHeaders()` methods. Both receive
the serialized object, which contains the attribute values:

```php
use BYanelli\Roma\Serialization\Serialized;

class TeapotResponse extends Response {
    public function responseStatus(Serialized $serialized): int {
        return 418;
    }
}
```

## Computed values

For a value derived from other properties, use a get-only hooked property. It is serialized
like any other property and appears in the generated TypeScript:

```php
class UserResponse extends Response {
    public string $fullName { get => "{$this->first} {$this->last}"; }

    public function __construct(public string $first, public string $last) {}
}
```

Overriding `toArray()` is the escape hatch for output the properties can't describe. Keys it
adds are not in the generated TypeScript. When the object is nested in another, the
serializer calls the override with its profile and normalizes the result with that profile,
so pass the profile on to keep the caller's enum style and date format:

```php
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Response\IsArrayable;
use BYanelli\Roma\Serialization\Profile;
use Illuminate\Contracts\Support\Arrayable;

class Swatch implements Arrayable {
    use IsArrayable;

    public function __construct(public Color $color) {}

    public function toArray(?Profile $profile = null): array {
        return [...Serializer::toArray($this, $profile), 'hex' => $this->color->hex()];
    }
}
```

An override declared as `toArray(): array` also works on a class that uses the trait
directly; it is called with an argument it ignores. A `Response` subclass must keep the
profile parameter. `Serializer::toArray($object)` and `Serializer::serialize($object)` always
serialize the object they are given by its properties, which is why the override can call
them without recursing. A `Response`'s HTTP body is its override's output, normalized with
the response profile; the status and headers still come from its `#[Status]` and `#[Header]`
properties.

## Date formats

Dates use `DateTimeInterface::ATOM` unless the property has `#[DateFormat('Y-m-d')]`. To
choose a format at runtime, implement `CustomizesSerialization`. Returning null falls back to
`#[DateFormat]` or the default:

```php
use BYanelli\Roma\Serialization\CustomizesSerialization;
use BYanelli\Roma\Serialization\Profile;

class EventResponse extends Response implements CustomizesSerialization {
    public function __construct(public DateTimeImmutable $startsAt, public bool $allDay) {}

    public function dateFormat(ReflectionProperty $property, Profile $profile): ?string {
        return $this->allDay ? 'Y-m-d' : null;
    }
}
```

## Serializing without a response

The `Serializer` facade serializes any object, with or without the traits:

```php
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Serialization\Profile;

Serializer::toArray($object);                      // the body, as a response would send it
Serializer::serialize($object);                    // body, headers, status, query and path
Serializer::toArray($object, Profile::storage());  // output ObjectMapper can map back
```

A `Profile` sets the enum style, the default date format and which destinations are allowed:

| Profile                    | Backed enums       | Destinations                 |
| -------------------------- | ------------------ | ---------------------------- |
| `Profile::response()`      | `{ name, value }`  | body, header, status         |
| `Profile::clientRequest()` | value              | body, query, path, header    |
| `Profile::storage()`       | value              | body                         |

The profile applies to nested objects too. A property whose destination the profile doesn't
allow, such as `#[Status]` under `Profile::storage()`, throws.
