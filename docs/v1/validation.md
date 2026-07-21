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
use BYanelli\Roma\Request\Attributes\Rule;

readonly class CreateContactRequest {
    #[Rule('max:255')]
    public string $name;

    #[Rule(['email', 'unique:contacts', 'max:255'])]
    public string $email;
}
```

Type coercion and enum/nested-object validation are applied automatically on top of your
rules — a `public int $page` is validated as an integer without you writing `integer`.

## Dynamic rules

A `#[Rule]` argument can also be a first-class-callable reference. Roma calls it through the
container at validation time, so the rule can depend on runtime state — a service, the
current user, config. It may return a single rule or a list, which is spread in place:

```php
use BYanelli\Roma\Request\Attributes\Rule;

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

## Guards

Mark a method `#[Guard]` to run it after validation passes. Guards are called through the
container, so they can type-hint dependencies and have them injected. A guard rejects the
request by throwing; its return value is ignored. Multiple guards run in declaration order.

```php
use BYanelli\Roma\Request\Attributes\Guard;
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
requirement on every request mapped to the class:

```php
use BYanelli\Roma\Request\Attributes\Accessors\Ajax;
use BYanelli\Roma\Request\Attributes\Headers\ContentType;
use BYanelli\Roma\Request\Enums\ContentType as ContentTypeEnum;

#[Ajax]                              // requires an AJAX request
#[ContentType(ContentTypeEnum::Json)] // requires a JSON Content-Type
class ApiOnlyRequest {
    public string $data;
}
```

## Error keys

When validation fails, Roma throws Laravel's `ValidationException` with errors keyed by a
**source-prefixed, request-relative** name, so the caller always knows where the offending
value belongs:

* `input.price` — merged input (query + body)
* `query.page` / `body.token` — a `#[Query]` / `#[Body]` property
* `header.X-Flag` — a header, by its real (un-normalized) name
* `route.id` — a `#[RouteParameter]` property
* `cookie.session` — a `#[Cookie]` property
* `request.ajax` — request metadata from an accessor

Nested fields keep their full path and array elements are indexed (`input.address.city`,
`input.items.1.code`). The one exception is a [precognitive request](/docs/roma/v1/precognition),
whose errors are keyed by the bare posted field name for front-end form tooling.

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

Use `#[Present]` on a nullable property when the key _must_ appear but may be `null`:

```php
use BYanelli\Roma\Request\Attributes\Present;

readonly class UpdateNoteRequest {
    #[Present]
    public ?string $note; // must be sent, but may be null
}
```
