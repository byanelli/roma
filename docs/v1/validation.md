---
extends: _layouts.docs.roma
section: content
title: Validation
package: roma
version: v1
weight: 2
group: Requests
---

# Validation

Attach validation rules with the `#[Rule]` attribute. Pass a single rule string, a
pipe-delimited string, or a list:

```php
use BYanelli\Roma\Attributes\Rule;

readonly class CreateContactRequest {
    #[Rule('max:255')]
    public string $name;

    #[Rule(['email', 'unique:contacts', 'max:255'])]
    public string $email;
}
```

Type coercion and enum/nested-object validation are applied automatically on top of your
rules — a `public int $page` is validated as an integer without you writing `integer`.
A value that isn't a case of an enum property's type gets a message that lists the valid
values: "The input.size field must be one of: s, m, l." A backed enum lists its values and
a unit enum its case names.

## Dynamic rules

A `#[Rule]` argument can also be a first-class-callable reference. Roma calls it through the
container at validation time, so the rule can depend on runtime state — a service, the
current user, config. It may return a single rule or a list, which is spread in place:

```php
use BYanelli\Roma\Attributes\Rule;

readonly class UpdateBioRequest {
    public function __construct(
        #[Rule('string', self::maxLength(...))]
        public string $bio = '',
    ) {}

    public static function maxLength(BioSettings $settings): string {
        return "max:{$settings->limit}";
    }
}
```

## Rules that refer to other fields

Each source is validated on its own, with keys relative to the source. A rule that refers to
another field, such as `required_if`, `same` or `gt`, gives that field's key as the client
sends it, without the source prefix:

```php
use BYanelli\Roma\Attributes\Rule;

readonly class PaymentRequest {
    public string $type;

    #[Rule('required_if:type,card')]
    public ?string $cardNumber = null;
}
```

The error key still has the prefix: `input.cardNumber`. A rule only sees the data of its own
source, so a `#[Query]` property's rule can't refer to an input field or a header. To check
values from different sources against each other, use a [guard](#guards).

## Guards

Mark a method `#[Guard]` to run it after validation passes. Guards are called through the
container, so they can type-hint dependencies and have them injected. A guard rejects the
request by throwing; its return value is ignored. Multiple guards run in declaration order.

```php
use BYanelli\Roma\Attributes\Guard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;

readonly class UpdatePostRequest {
    public function __construct(
        public int $postId,
        public string $body,
    ) {}

    #[Guard]
    public function authorize(#[CurrentUser] User $user): void {
        if ($user->cannot('update', Post::findOrFail($this->postId))) {
            throw new AuthorizationException;
        }
    }
}
```

## Class-level constraints

Some accessor and header attributes can be applied at the class level to enforce a global
requirement on all requests mapped to the class:

```php
use BYanelli\Roma\Http\Attributes\Accessors\Ajax;
use BYanelli\Roma\Http\Attributes\Headers\ContentType;
use BYanelli\Roma\Http\Enums\ContentType as ContentTypeEnum;

#[Ajax]                              // requires an AJAX request
#[ContentType(ContentTypeEnum::Json)] // requires a JSON Content-Type
class ApiOnlyRequest {
    public string $data;
}
```

## Error keys

When a request fails validation, Roma throws Laravel's `ValidationException` with errors keyed by a
**source-prefixed, request-relative** name, so the caller always knows where the offending
value belongs:

* `input.price` — merged input (query + body)
* `query.page` / `body.token` — a `#[Query]` / `#[Body]` property
* `header.X-Flag` — a header, by its real (un-normalized) name
* `path.id` — a `#[Path]` property
* `cookie.session` — a `#[Cookie]` property
* `request.ajax` — request metadata from an accessor
* `tenant.plan` — a [custom source](/docs/roma/v1/sources#custom-sources), under its key

Nested fields keep their full path and array elements are indexed (`input.address.city`,
`input.items.1.code`). The one exception is a [precognitive request](/docs/roma/v1/precognition),
whose errors are keyed by the bare posted field name for front-end form tooling.

## Mapping outside a request

`Mapper::fromArray()` maps a plain array, such as a tool's arguments, a config array or a
stored value, onto the same classes. A failure there throws
`BYanelli\Roma\Data\MappingValidationException`, which Laravel renders as a 500 if nothing
catches it, so data your code maps during a request, such as a config array, never answers
the caller with a 422. Its `errors()` are keyed under `data.` and `className()` returns the
class being mapped:

```php
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;

try {
    $settings = Mapper::fromArray(Settings::class, $array); // a Settings instance
} catch (MappingValidationException $e) {
    $e->errors();    // ['data.timeout' => ['The data.timeout field must be an integer.']]
    $e->className(); // Settings::class
}
```

A property with an HTTP source attribute such as `#[Query]`, `#[Header]` or `#[Status]`
throws, since a plain array has none of them. PHPStan infers the return type from the class
name.

Request mapping converts it to Laravel's `ValidationException`, so a request still gets a 422.

## Nullable and optional

A non-nullable property with no default is **required**. Give it a default to make it
optional; making the type nullable (`?T`) also makes it optional — an absent _or_
explicitly-`null` key resolves to `null` (Roma applies `nullable` rather than `required`).

```php
class ProductSearchRequest {
    public string $name;      // required
    public int $perPage = 15; // optional (has a default)
    public ?string $search;   // optional; null when absent or null
}
```

### Telling absent from `null` with `Missing`

`#[Optional]` makes a property never required. To tell an absent key from an explicit
`null`, add `BYanelli\Roma\Missing` to the type: an absent key maps to the shared
`Missing::instance()`, so you can test `$request->bio instanceof Missing`. A type with
`Missing` makes the property optional by itself, so `#[Optional]` is allowed but redundant
on it. An absent `#[Optional]` property without `Missing` in its type is left unset.

```php
use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Missing;

readonly class UpdateProfileRequest {
    public function __construct(
        public string|Missing $bio,                    // Missing when absent
        #[Optional] public ?string $nickname,          // null when absent
        public Address|Missing|null $home,             // Missing when absent, null when null
    ) {}

    #[Optional]
    public string $website;                            // unset when absent
}
```

Coercion, validation, TypeScript and serialization treat `string|Missing` as `string`; a
present value must still be a valid string. TypeScript emits `bio?: string`, and the
serializer omits a property whose value is `Missing`, so the object serializes back to the
input it was mapped from. An `#[Optional]` constructor parameter must be nullable, include
`Missing` or have a default; otherwise it throws when the class definition is built.
`#[Present]` on a type with `Missing` throws too, and so does a type with nothing but
`Missing` and `null`, such as `?Missing`: add the type of the value, e.g. `string|Missing`.

Use `#[Present]` on a nullable property when the key _must_ appear but may be `null`:

```php
use BYanelli\Roma\Attributes\Present;

readonly class UpdateNoteRequest {
    #[Present]
    public ?string $note; // must be sent, but may be null
}
```

`#[Present]` together with `#[Optional]` throws, since one requires the key and the other
allows it to be absent. `#[Present]` on a property with a default value throws too, since the
default would never be used.
