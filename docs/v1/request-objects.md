---
extends: _layouts.docs.roma
section: content
title: Request Objects
package: roma
version: v1
weight: 1
group: Requests
---

# Request objects

A request object is a class whose typed properties you want populated from the request.
Constructor-promoted properties and plain class properties can be used interchangeably.

```php
use BYanelli\Roma\Attributes\Rule;

readonly class CreateContactRequest {
    public function __construct(
        #[Rule('max:255')]
        public string $name,

        #[Rule(['email', 'unique:contacts', 'max:255'])]
        public string $email,
    ) {}

    #[Rule('phone')]
    public string $phone;
}
```

## Inject it into your controller

Mark the request _class_ with `#[Request]` and type-hint it in your controller. Roma
resolves it from the container, maps the request onto it, and validates it before your
action runs:

```php
use BYanelli\Roma\Http\Request\Injection\Request;

#[Request]
readonly class CreateContactRequest { /* properties as above */ }

class CreateContactController {
    public function __invoke(CreateContactRequest $request) {
        Contact::create([
            'name'  => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
        ]);
    }
}
```

Marking the class is also what lets the [TypeScript generator](/docs/roma/v1/typescript)
auto-detect it as a request. Auto-injection is on by default; to require an explicit
attribute everywhere, set `auto_inject` to `false` in `config/roma.php`.

### Annotating the parameter instead

`#[Request]` also works on the controller parameter rather than the class. Both forms work
and can coexist — reach for the parameter attribute when auto-injection is off, or for a
one-off request you'd rather not mark at the class level:

```php
use BYanelli\Roma\Http\Request\Injection\Request;

class CreateContactController {
    public function __invoke(
        #[Request]
        CreateContactRequest $request,
    ) {
        // ...
    }
}
```

## Map only the body

`#[RequestBody]` on a controller parameter maps the request's JSON or form body onto the class
as a plain array, the way `Mapper::fromArray()` does. Use it instead of `#[Request]` for a class
that has no request dependency: one that also describes a queued job's payload, a tool's
arguments or a stored value, and is mapped from an array there.

```php
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Http\Request\Injection\RequestBody;

readonly class ContactData {
    public function __construct(
        public string $name,
        #[Rule('email')]
        public string $email,
    ) {}
}

class CreateContactController {
    public function __invoke(
        #[RequestBody]
        ContactData $contact,
    ) {
        // ...
    }
}

// Elsewhere, from an array:
$contact = Mapper::fromArray(ContactData::class, $payload);
```

- The body is the only source. The query string is ignored, and a property with `#[Query]`,
  `#[Header]`, `#[Path]`, `#[Cookie]` or a request accessor throws when the class definition is
  built. The `_method` and `_token` fields of Laravel's forms are left out of the data.
- POST is a `Create`, and PUT and PATCH are an `Update`, as with `#[Request]`, so
  `#[RequiredOnCreate]` and `#[OptionalOnUpdate]` apply.
- A failure is a `422` with errors keyed and worded as Laravel's validator does them, such as
  `email` and `config.to_emails.0`, whatever `source_prefixed_errors` says. The class can
  implement `HandlesFailedValidation`, and [Precognition](/docs/roma/v1/precognition) works
  as it does for `#[Request]`.

## Map only the query string

`#[RequestQuery]` does the same for the query string, such as a search or a list's filters.
The mapping is always `Operation::None`, and the body is ignored.

```php
use BYanelli\Roma\Attributes\Delimited;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Http\Request\Injection\RequestQuery;

readonly class ProductFilters {
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        #[Rule('min:3')]
        public ?string $search = null,
        public bool $inStock = false,
        #[Delimited]
        public array $tags = [],
        public int $page = 1,
    ) {}
}

class ListProductsController {
    public function __invoke(
        #[RequestQuery]
        ProductFilters $filters,
    ) {
        // GET /products?search=lamp&inStock=1&tags=desk,office&page=2
    }
}
```

Query string values are strings and arrays, and they're coerced as they are for `#[Query]`:
`"2"` maps to `2`, and `"1"`, `"true"`, `"on"` and `"yes"` map to `true`. Errors,
`HandlesFailedValidation` and Precognition work as they do for `#[RequestBody]`.

## Share properties with traits

Common properties can be factored into a trait and mixed into any request:

```php
use BYanelli\Roma\Attributes\Rule;

trait HasPagination {
    #[Rule('integer|min:1')]
    public int $page = 1;

    #[Rule('integer|min:1|max:100')]
    public int $perPage = 15;
}

class ProductListRequest {
    use HasPagination;

    public ?string $search;
}
```
