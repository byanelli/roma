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
use BYanelli\Roma\Request\Attributes\Rule;

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
use BYanelli\Roma\Request\ContextualBinding\Request;

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
use BYanelli\Roma\Request\ContextualBinding\Request;

class CreateContactController {
    public function __invoke(#[Request] CreateContactRequest $request) {
        // ...
    }
}
```

## Share properties with traits

Common properties can be factored into a trait and mixed into any request:

```php
use BYanelli\Roma\Request\Attributes\Rule;

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
