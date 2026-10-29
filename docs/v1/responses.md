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
use BYanelli\Roma\Http\Response\Response;

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
`json_encode($response)` produces the same output as `toResponseBody()`, which serializes
with the response profile and normalizes an overridden `toArray()` (see below). `toArray()`
itself uses the storage profile; see
[Serializing without a response](#serializing-without-a-response).

## Rename a key with `#[Key]`

A property serializes under its PHP name unless it has `#[Key]`. It is the same attribute
that sets a key when mapping input, so a class used both ways reads and writes the same key.
A key given to a source attribute, such as `#[Query('page_num')]`, is also written:

```php
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Http\Response\Response;

class UserResponse extends Response {
    #[Key('user_id')]
    public int $id;              // {"user_id": 42}
}
```

A dotted key is written as nested objects, and keys under one parent share one object:

```php
class ErrorResponse extends Response {
    #[Key('error.message')]
    public string $message;      // {"error": {"message": "Not found", "code": 404}}

    #[Key('error.code')]
    public int $code;
}
```

An `#[Optional]`, `Missing` or `#[\SensitiveParameter]` property that is left out leaves no
key behind, and a parent whose values are all left out is left out too. An escaped dot, as in
`#[Key('created\.at')]`, is part of the key, and a header name keeps its dots. A property
written inside another property's value, such as `#[Key('error')]` beside
`#[Key('error.message')]`, throws.

`#[Key]` together with `#[Header('Name')]` throws, since the header name is already the key.
`#[Key]` together with `#[Status]` throws too, since the status has no key.

## Omit unset properties with `#[Optional]`

A response property has no implicit default: leaving it unset makes serialization throw,
surfacing a field you forgot to populate. Mark it `#[Optional]` to omit it when unset, or
give it an explicit default to serialize that value:

```php
use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Http\Response\Response;

class ContactResponse extends Response {
    public string $name;

    #[Optional]
    public ?string $nickname;    // omitted entirely when unset

    public ?string $note = null; // an explicit default serializes as null
}
```

A property typed with `BYanelli\Roma\Missing`, such as `string|Missing`, is optional without
the attribute: it is omitted when unset or when its value is `Missing::instance()`, and
TypeScript marks it optional.

## Leave out secrets with `#[\SensitiveParameter]`

A property whose constructor parameter has PHP's `#[\SensitiveParameter]` is left out of a
response. Its key is left out of the body and the headers, including in nested objects, and
the generated TypeScript response types leave it out as well. `toSafeArray()` leaves it out
too, for logs and other output that leaves the system. The storage profile, which
`toArray()` uses by default, and the [HTTP client's](/docs/roma/v1/http-client) profiles write
it: stored data maps back to an equal object, a request sends the value to the server, and a
fake response includes it. A property assigned in the constructor from a sensitive parameter
of the same name is treated the same way.

```php
use BYanelli\Roma\Facades\Serializer;

readonly class ProxyCredentials {
    public function __construct(
        public string $user,
        #[\SensitiveParameter]
        public string $password,
    ) {}
}

$credentials = new ProxyCredentials('ox', 'secret');

Serializer::toResponseBody($credentials); // ['user' => 'ox']
Serializer::toSafeArray($credentials);    // ['user' => 'ox']
Serializer::toArray($credentials);        // ['user' => 'ox', 'password' => 'secret']
```

Request types in TypeScript keep the property, since the client sends it, so a class
nested in both a request and a response gets
[a separate response type](/docs/roma/v1/typescript#classes-used-in-requests-and-responses).

## Status and headers

Mark an `int` property `#[Status]` to make its value the HTTP status code, or a property
`#[Header('Name')]` to emit it as a response header. Both are lifted out of the body:

```php
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Response\Response;

class CreatedResponse extends Response {
    public string $name = 'Bill';

    #[Status]
    public int $status = 201; // response is 201; body is {"name":"Bill"}

    #[Header('Cache-Control')]
    public string $cacheControl = 'max-age=3600';
}
```

A property can have only one of `#[Status]` and `#[Header]`, and both can only be declared
on the top-level class: a nested object with either throws when it is serialized or its
definition is built. The `#[Status]` property must be typed `int`; for a status that is
usually the same, give it a default such as `201` rather than making it nullable, or put
`#[Status(201)]` on the class, which applies when the class has no `#[Status]` property. A
class mapped from a request can't have `#[Status]`, since a request has no status. Without
either, the response defaults to 200. For values computed at
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

A hooked property with no backing value is never mapped, so `#[Rule]`, `#[Present]`, or a
source attribute that is only a source, such as `#[Input]` or `#[Cookie]`, on one throws.
`#[Key]`, `#[Header]`, `#[Query]`, `#[Body]`, `#[Path]`, `#[DateFormat]` and `#[Description]`
apply to it as usual, since they also say where the value is written.

### Transforming a value on the way out

`#[SerializeUsing]` passes a property's value through a callable before it is serialized,
including `null` but not `Missing`. The object keeps its value; only the output changes.
Several apply in declaration order. A callable of your own that declares a second parameter
also receives the object, so it can use other properties. A PHP function such as
`strtoupper(...)` receives the value alone:

```php
use BYanelli\Roma\Attributes\SerializeUsing;

class LeadResponse extends Response {
    public function __construct(
        public string $first,
        public string $last,

        #[SerializeUsing(self::fullName(...))]
        public ?string $fullName = null,

        #[SerializeUsing(strtoupper(...))]
        public string $status = 'new',
    ) {}

    public static function fullName(?string $fullName, self $lead): string {
        return $fullName ?? "{$lead->first} {$lead->last}";
    }
}
```

An attribute that implements `BYanelli\Roma\Serialization\SerializeTransform` is applied the
same way, through `serializeValue($value, $object)`. TypeScript and JSON Schema still describe
the property's declared type. To change the value the object itself holds, use
[`#[MapFromValue]`](/docs/roma/v1/sources#transforming-values) instead.

Overriding `toArray()` is the escape hatch for output the properties can't describe. Keys it
adds are not in the generated TypeScript. When the object is nested in another, the
serializer calls the override with its profile and normalizes the result with that profile,
so pass the profile on to keep the caller's enum style and date format:

```php
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Serialization\IsArrayable;
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

Wherever Roma consumes an override's output (`json_encode`, `toResponse()` and nesting), it
converts the values the same way as property values, so an enum, a date or a nested object
added as an extra key comes out in its JSON form. Calling `$object->toArray()` directly
returns exactly what the override returns.

## Date formats

Dates use `DateTimeInterface::ATOM` unless the property has `#[DateFormat('Y-m-d')]`, which
also sets the format input must have when the class is mapped (see
[Sources](/docs/roma/v1/sources#type-coercion-and-enums)). It applies to the property's
own value and to the dates in an array, map or shape it contains. A nested object, including
one with its own `toArray()`, writes its dates with its own formats. To
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

Serializer::toArray($object);                       // output Mapper::fromArray() can map back
Serializer::toSafeArray($object);                   // the same, without sensitive values
Serializer::toResponseBody($object);                // the body, as a response would send it
Serializer::serialize($object);                     // body, headers, status, query, path and URL
Serializer::toArray($object, Profile::response());  // any profile
```

`serialize()` and `toArray()` also take an `Operation` (see
[Validation](/docs/roma/v1/validation#required-on-create-optional-on-update)). With
`Operation::None`, the default, a `Missing` property is omitted. With `Create` or `Update`, a
`Missing` property that the operation requires, such as a `#[RequiredOnCreate]` property in a
`Create`, throws `InvalidArgumentException` instead, listing the class and the properties. The
check applies to the object's own properties, not those of nested objects:

```php
use BYanelli\Roma\Data\Operation;

Serializer::toArray($request, operation: Operation::Create);
// Roma cannot serialize App\Http\Requests\ContactRequest for a Create, since $email is Missing.
```

`toArray()` uses `Profile::storage()`, for casts, caches and other data that is read back.
Use `toSafeArray()` for logs, debug output and anything else that leaves the system, and
`toResponseBody()` to build a response body by hand. `serialize()` uses `Profile::response()`.
A class that uses `IsArrayable` has the same `toArray()`, `toSafeArray()` and
`toResponseBody()` methods; `jsonSerialize()` returns `toResponseBody()`, so an object returned
from a controller or passed to `response()->json()` is sent with the response profile. The two
methods without a profile normalize an overridden `toArray()`'s output.

A `Profile` sets the enum style, the default date format and which destinations are allowed:

| Profile                     | Backed enums       | Destinations                   | Sensitive values |
| --------------------------- | ------------------ | ------------------------------ | ---------------- |
| `Profile::response()`       | `{ name, value }`  | body, header, status           | left out         |
| `Profile::clientRequest()`  | value              | body, query, path, header, URL | written          |
| `Profile::clientResponse()` | value              | body, header, status           | written          |
| `Profile::storage()`        | value              | body                           | written          |

`Profile::storage()->withoutSensitive()`, which `toSafeArray()` uses, has the storage settings
and leaves sensitive values out. `withoutSensitive()` applies to any profile.

The profile applies to nested objects too. A property whose destination the profile doesn't
allow, such as `#[Status]` under `Profile::storage()`, throws, so call `toResponseBody()` or
`serialize()` on a response object with `#[Status]` or `#[Header]` properties. `#[Query]`, `#[Path]` and
`#[Body]` are sources as well, so under such a profile their properties are written to the
body, and a request object serializes back to the keys it maps from.
