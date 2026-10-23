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

A property is populated from a **source** — a part of the request. By default a
property reads from the merged input bag (query string + body). Attributes bind it to a
specific source instead.

## Query and body

Use `#[Query]` or `#[Body]` to pin a property to one bag — handy when the same key can
appear in both:

```php
use BYanelli\Roma\Http\Attributes\Body;
use BYanelli\Roma\Http\Attributes\Query;

readonly class SearchRequest {
    #[Query]
    public int $page;     // always from the query string

    #[Body]
    public string $token; // always from the request body
}
```

### Renaming a key

A source attribute takes the key as its argument, and `#[Key]` sets it within whichever
source the property reads from. The serializer writes a property under the same key, whether
it comes from `#[Key]` or from a source attribute such as `#[Query('page_num')]`, so an object
mapped from input serializes back to the same keys:

```php
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Http\Attributes\Query;

readonly class SearchRequest {
    #[Query('page_num')]
    public int $page;       // from query.page_num

    #[Key('per_page')]
    public int $perPage;    // from input.per_page
}
```

### Snake case keys

`#[SnakeCaseKeys]` on a class gives its properties, and those of its subclasses, the
snake_case of their names as their keys, for mapping, serialization, TypeScript and the HTTP
client. A key attribute still sets a property's key, and a nested class uses its own
attribute:

```php
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\SnakeCaseKeys;
use BYanelli\Roma\Http\Attributes\Path;

#[SnakeCaseKeys]
readonly class NewLeadRequest {
    public string $firstName;   // from input.first_name

    #[Path]
    public string $clientId;    // from the {client_id} route parameter

    #[Key('surname')]
    public string $lastName;    // from input.surname
}
```

An empty key, as in `#[Key('')]` or `#[Query('')]`, throws. A property may get its key from
only one attribute: `#[Query('page_num')]` together with `#[Key('page')]`, or
`#[Header('X-Foo')]` with `#[Key('foo')]`, throws when the class definition is built. So does a property with two source attributes, such as `#[Query]` with
`#[Body]`, and a source attribute on an `UploadedFile` property, which always maps from the
uploaded files.

Two properties on the same key of one source throw too, such as `#[Query('page')]` on two
properties, `#[Input('a')]` beside a property named `a`, or `#[Header('X-Flag')]` beside
`#[Header('x-flag')]`, since header names ignore case. Several properties may read one
accessor such as `#[Ajax]`.

### Nested keys

A dot in a key reads a nested value, as in Laravel's `$request->input('error.message')`:

```php
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Http\Attributes\Query;

readonly class ReportRequest {
    #[Key('error.message')]
    public string $message;   // from {"error": {"message": "..."}}

    #[Query('filter.status')]
    public string $status;    // from ?filter[status]=open

    #[Key('created\.at')]
    public string $createdAt; // from {"created.at": "..."}
}
```

- Errors use the full path: `input.error.message`, `query.filter.status`.
- An escaped dot, as in `created\.at`, is part of the key, as in a Laravel rule key. Its
  error key is `input.created.at`.
- Header, cookie and route parameter names are flat, so a dot in `#[Header]`, `#[Cookie]` or
  `#[Path]` is part of the name, without escaping.
- A key inside another property's key in the same source, such as `#[Key('error')]` beside
  `#[Key('error.message')]`, throws when the class definition is built. Keys in different
  sources, such as `#[Query('error')]` and `#[Body('error.message')]`, don't conflict.
- The serializer writes a dotted key as nested objects, so the class serializes back to the
  shape it was mapped from (see [Responses](/docs/roma/v1/responses#rename-a-key-with-key)).

Default keys come from property names, which can't contain a dot, so a property without a
key attribute is unaffected.

### The QUERY method

`QUERY` ([RFC 10008](https://www.rfc-editor.org/info/rfc10008/)) is a safe, idempotent
method that carries its input in the request content instead of the URI — "GET with a
body". Roma treats that content as the body, so `#[Body]` and the default input source
both read it, and `#[Query]` still means the query string:

```php
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Enums\Method;

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

Bind to a route parameter with `#[Path]`. The property name is the parameter name
unless you pass an explicit one. Route parameters arrive as strings, so scalar and enum
coercion applies:

```php
use BYanelli\Roma\Http\Attributes\Path;

// Route: /users/{id}/posts/{post_slug}
readonly class ShowPostRequest {
    #[Path]
    public int $id;      // from {id}, coerced "42" -> 42

    #[Path('post_slug')]
    public string $slug; // from {post_slug}
}
```

A missing route parameter fails validation under a `path.` key, such as `path.id`, including
when the request has no bound route.

## Cookies

Bind to a cookie with `#[Cookie]`, using the property name or an explicit cookie name.
A cookie name may contain a dot, which doesn't nest, so pass the name explicitly when it
isn't a valid PHP property name:

```php
use BYanelli\Roma\Http\Attributes\Cookie;

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

## Custom sources

A source is a class that extends `BYanelli\Roma\Data\Source`, used through an attribute
that implements `SourceAttribute`. This one reads the request attributes, which middleware
often sets:

```php
use Attribute;
use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Data\Source;
use Illuminate\Http\Request;

/** @extends Source<Request> */
final readonly class Tenant extends Source
{
    public function getKey(): string
    {
        return 'tenant';                  // the prefix of its error keys
    }

    public function inputType(): string
    {
        return Request::class;
    }

    /**
     * @param  Request  $input
     */
    protected function read(object $input): array
    {
        return $input->attributes->all();
    }
}

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class FromTenant implements ExplicitKeyAttribute, SourceAttribute
{
    public function __construct(public ?string $key = null) {}

    public function getSource(): Source
    {
        return new Tenant;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }
}

readonly class InvoiceRequest {
    #[FromTenant('tenant_id')]
    public int $tenantId;                 // from $request->attributes->get('tenant_id')

    #[FromTenant]
    public string $plan;
}
```

`read()` returns the source's values keyed by property key. Roma calls it only for a class
with a property from the source, and only with an instance of `inputType()`. The
`@extends Source<Request>` tag gives PHPStan the same class, so it types `$input` as a
`Request` and reports an `inputType()` that returns another class. PhpStorm doesn't apply
the tag to an overridden method, so the `@param` on `read()` repeats the type for it. A source is allowed
wherever the input is of its type, so `Tenant` works in a request, and a class that uses it
throws under `Mapper::fromArray()`.

Five methods are optional. `normalizeKey()` converts a property's key to its key in
`read()`'s array, as the header source does with `Content-Type` and `content_type`.
`isSingleValue()` returns true when `read()` returns one value for the property instead of an
array, as for an accessor such as `#[Ip]`; that property's error key is the source's key.
`nestsKeys()` returns false when `read()`'s array is flat, as headers are, so that a dot in a
key is part of the key instead of [separating nested keys](#nested-keys).
`checkedKeys()` returns the top-level keys of `read()`'s array that
[`#[RejectUnknownKeys]`](/docs/roma/v1/validation#rejecting-unknown-keys) checks, or null for
all of them; it returns none by default. `sharesKeysWith()` returns true for another source
whose properties' keys count as known for those keys, as the input and `#[Body]` sources do.

The source's data is validated on its own, and its errors are keyed under its key, such as
`tenant.tenant_id`. Two different sources with the same key in one class throw when the
class definition is built.

## Type coercion and enums

Roma coerces string input to the property's declared type, and maps values onto
string-backed, integer-backed, and unit enums automatically:

```php
class OrderRequest {
    public float $price;                   // "9.99" -> 9.99
    public bool $isGift;                   // "yes" -> true, "Y" -> false
    public \DateTimeInterface $deliverBy;  // "2024-01-01" -> DateTime
    public Status $status;                 // "complete" -> Status::Complete (string enum)

    /** @var array<int> */
    public array $itemIds;                 // ["1","2"] -> [1, 2]
}
```

A date property accepts anything Laravel's `date` rule does. With `#[DateFormat('Y-m-d')]`
it must match that format instead, validated by `date_format:Y-m-d`, and is parsed with
`createFromFormat` into the declared class. Fields the format doesn't contain are zero, so
`Y-m-d` gives midnight. The serializer writes the date in the same format:

```php
use BYanelli\Roma\Attributes\DateFormat;

class BookingRequest {
    #[DateFormat('Y-m-d')]
    public CarbonImmutable $checkIn;   // "2024-03-05" -> 2024-03-05 00:00:00
}
```

The attribute applies to the property's own value, which includes the elements of a
`list<DateTimeImmutable>`, the values of an `array<string, DateTimeImmutable>` and the dates
in an array shape. It doesn't apply to the dates inside a nested object, which follow the
nested class's own attributes. On a type with no date of its own, such as `string`, a nested
class, `mixed`, no type, or an array without a PHPDoc type of dates, it throws. A union with a
date, such as `DateTimeImmutable|string`, is allowed; it is mapped as given and serialized
with the format.

A `bool` reads a value as Laravel's `Request::boolean()` does: `true`, `1`, and the strings
`"1"`, `"true"`, `"on"` and `"yes"` in any case are true, and any other scalar, including
`"0"`, `"no"`, `"Y"` and `""`, is false. An array or an object fails validation with Laravel's
boolean message, and a `null` is handled as for any other type (see
[`null` and defaults](/docs/roma/v1/validation#null-and-defaults)).

A `string` accepts a JSON number and maps it to its PHP string form, so `35201` becomes
`"35201"` and `1.5` becomes `"1.5"`, as in a list or an array shape of strings. Rules such as
`max:5` see the string. A bool still fails with Laravel's string message. Query, form and
header values are strings already.

To reject numbers, add `#[RejectNumbers]` to the property, or to a class to cover all its
string properties and those of its subclasses. A number then fails with the `string` rule's
message, which `#[Message(string: '...')]` can replace. On a property without a string in its
type it throws when the class definition is built:

```php
use BYanelli\Roma\Attributes\RejectNumbers;

readonly class LeadRequest {
    public function __construct(
        public string $zip,           // 35201 -> "35201"

        #[RejectNumbers]
        public string $code,          // 35201 -> "The input.code field must be a string."
    ) {}
}
```

Three more types cover values that usually arrive as strings: comma-separated lists,
durations and sizes.

```php
use BYanelli\Roma\Attributes\Delimited;
use BYanelli\Roma\Attributes\Unit;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Values\ByteSize;
use Carbon\CarbonInterval;

class ExportRequest {
    /** @var list<string> */
    #[Query]
    #[Delimited]
    public array $columns;           // ?columns=id,name, email -> ["id", "name", "email"]

    public CarbonInterval $window;   // "90 minutes", "PT2H"

    #[Unit('seconds')]
    public CarbonInterval $timeout;  // "30" -> 30 seconds

    public ByteSize $maxSize;        // "25MB", "512KiB" or 1048576
}
```

`#[Delimited]` splits on `,` unless you pass another delimiter, trims the items, drops empty
ones, and passes an array value through. See
[Lists, durations and sizes](/docs/roma/v1/config-objects#lists-durations-and-sizes) for the
formats that intervals and sizes accept.

An `array` property or constructor parameter needs its element type in PHPDoc: `@var` on a
property, `@param` on a constructor parameter. Roma accepts `array<T>` and `list<T>` (and
`non-empty-array<T>` / `non-empty-list<T>`), where `T` is a scalar, `mixed`, an enum, a
class, `self`, or another array type. A `list<T>` also validates that the input keys are
sequential (Laravel's `list` rule).

A class name in PHPDoc resolves as PHP resolves one in code, using the `use` imports and
namespace of the file that declares the property, which for an inherited property is the
trait's or parent class's file. `\App\Billing\Card` is fully qualified. `Card` resolves
through `use App\Billing\Card;`, `use App\Billing\{Card, Voucher};` or an alias, and
`Billing\Card` through `use App\Billing;`. Any other name is in the declaring namespace. Roma
also accepts a name as written, such as `App\Billing\Card` or `DateTimeImmutable` without an
import. A name that resolves to no class throws `UnresolvableClassName` when the class
definition is built, in mapping, serialization and TypeScript generation.

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

## Transforming values

`#[MapUsing]` passes a property's value through a callable after validation, before the
object is constructed. In an attribute the callable is a first-class callable, such as
`strtolower(...)` or `self::normalize(...)`, or a `static function` closure; PHP doesn't allow
arrow functions there. Several apply in declaration order:

```php
use BYanelli\Roma\Attributes\MapUsing;

readonly class SignupRequest {
    public function __construct(
        #[MapUsing(trim(...))]
        #[MapUsing(strtolower(...))]
        public string $email,
    ) {}
}
```

The callable receives the value the property would get, including its default or `null`, but
not `Missing`, and a nested object arrives constructed. A result the property's type doesn't
accept throws a `RuntimeException` naming the property.

### Values computed from the request

A callable whose parameter is typed as the input being mapped, Laravel's `Request` for a
request object, receives the request instead, and its result is the property's value. The
property isn't read from the input or validated, so the callable decides how lenient to be.
It can call the class's private static methods:

```php
use BYanelli\Roma\Attributes\MapUsing;
use Illuminate\Http\Request;

readonly class NewLeadRequest {
    public function __construct(
        public string $first_name,
        public string $last_name,

        #[MapUsing(static function (Request $request): string {
            return $request->input('full_name') ?: "{$request->input('first_name')} {$request->input('last_name')}";
        })]
        public string $full_name,

        #[MapUsing(static function (Request $request): bool {
            return $request->boolean('is_test') || str_contains($request->input('email', ''), 'test');
        })]
        public bool $is_test,
    ) {}
}
```

Such a callable must be the property's first transform; later ones receive its result. A
property with one can't have a source attribute, can't be inside a nested object, and is left
out of TypeScript and JSON Schema. Mapped with `Mapper::fromArray()`, where there is no
request, the class throws when its definition is built, since the callable's parameter is
neither the property's type nor the mapping's input.

A non-promoted property works too, and Roma sets it after the constructor runs. PHPStan
reports a non-promoted `readonly` property that isn't assigned in the constructor, so prefer a
promoted parameter in a readonly class.

### Cutting strings with `#[Limit]`

`#[Limit(200)]` cuts a string property to 200 characters with Laravel's `Str::limit()`, which
appends `...` to a cut string, so a cut value is 203 characters long. Pass `end: ''` to cut
without a marker. A string that fits and `null` pass through, and any other value throws an
`InvalidArgumentException`:

```php
use BYanelli\Roma\Attributes\Limit;

readonly class NewLeadRequest {
    public function __construct(
        #[Limit(200)]
        public ?string $site = null,          // 250 characters -> 200 + "..."

        #[Limit(50, end: '')]
        public ?string $source = null,        // at most 50 characters
    ) {}
}
```

### Your own transforms

An attribute that implements `BYanelli\Roma\Data\Attributes\MapTransform` is applied the same
way, through its `mapValue()` method:

```php
use Attribute;
use BYanelli\Roma\Data\Attributes\MapTransform;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Slug implements MapTransform {
    public function mapValue(mixed $value): mixed {
        return is_string($value) ? str($value)->slug()->toString() : $value;
    }
}
```
