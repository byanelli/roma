---
extends: _layouts.docs.roma
section: content
title: TypeScript Generation
package: roma
version: v1
weight: 1
group: TypeScript
---

# TypeScript generation

Roma generates TypeScript definitions for your request and response objects, so the
frontend and backend share one source of truth. Run:

```bash
php artisan roma:typescript
```

It writes a `.d.ts` file (default `resources/js/roma.d.ts`, overridable with `--output` or
config) containing interfaces for the request and response classes.

## What gets generated

A request is split into up to three interfaces — one per HTTP location its properties come
from — named `{Name}Body`, `{Name}Query`, and `{Name}Headers`; empty ones are dropped. A
response produces a `{Name}Body`, plus a `{Name}Headers` when it emits `#[Header]`s. Fields
are keyed by their **wire key** (the source key, or a `#[Key]`/header name), and optional
properties get a `?`.

```php
#[Request]
readonly class SearchRequest {
    public function __construct(
        public string $note,                          // default (input) -> Body
        #[Query] public int $page = 1,                // -> Query (optional)
        #[Header('X-Api-Key')] public string $apiKey, // -> Headers
    ) {}
}
```

generates:

```typescript
export interface SearchRequestBody {
  note: string;
}

export interface SearchRequestHeaders {
  'X-Api-Key': string;
}

export interface SearchRequestQuery {
  page?: number;
}
```

Enums become a named `const` of `{ name, value }` objects plus a union type, emitted ahead
of the interfaces that use them. A map such as `array<string, T>` becomes `Record<string, T>`
(`Record<number, T>` for `int` keys), and an array shape becomes an inline object type such
as `{ input: number; output?: number }`. A class that contains itself refers to its own
interface by name, e.g. `replies?: Comment[]`.

A `#[Description]` on a class or property becomes a doc comment:

```typescript
/** Search the catalog. */
export interface SearchRequestBody {
  /** Words to match against titles. */
  query: string;
}
```

## Discriminated unions

A [discriminated union](/docs/roma/v1/nested-objects#discriminated-unions) becomes a union of
its member interfaces, each combined with its discriminator value. A union declared on an
interface or abstract class is emitted once as a type alias named after it:

```typescript
export type PaymentMethod = ({ type: 'Card' } & Card) | ({ type: 'bank' } & BankAccount);

export interface CheckoutRequestBody {
  payment: PaymentMethod;
  fallbacks?: PaymentMethod[];
  refundTo?: ({ type: 'Card' } & Card) | ({ type: 'Voucher' } & Voucher) | null;
}

export interface Card {
  last4: string;
}
```

A union declared on the property is written inline. The member interfaces don't contain the
key, since a property typed as the member class is serialized without it. A property typed
as an interface, an abstract class or a union of classes without `#[Discriminated]` makes
generation throw.

## Classes used in requests and responses

Requests and responses mark optional properties differently. A request property is optional
when it isn't required: it has `#[Optional]`, a `Missing` type or a default value. A response
property is optional only with `#[Optional]` or `Missing`, because the serializer always
writes a property that has a default. Response types also leave out
[`#[\SensitiveParameter]`](/docs/roma/v1/responses) properties, which request types keep.

A nested class used in both a request and a response gets one interface when the two sets of
rules give the same result. When they don't, the response gets its own interface, named with
a `Response` suffix:

```php
readonly class Address {
    public function __construct(
        public string $street,
        public string $country = 'US',
    ) {}
}
```

```typescript
export interface Address {
  street: string;
  country?: string;
}

export interface AddressResponse {
  street: string;
  country: string;
}
```

A class that contains `Address` gets a response interface as well, since its response type
refers to `AddressResponse`, and so does a union alias with such a member. A class nested only
in responses keeps its name. The suffix follows a `#[TypeScriptName]`, and a class already
named `...Response` gets it too. When another type already has the name, a number follows the
suffix (`AddressResponse2`) and the other type keeps its name.

## Auto-detection

Classes are discovered by scanning the directories in `roma.discover` (default
`app/`) — there is no list to maintain by hand:

* a **request** is any class marked with a class-level `#[Request]` attribute;
* a **response** is any class extending `Response` or using the `IsResponsable` trait.

The `requests` and `responses` config lists are an additive escape hatch for classes
outside the scanned directories.

## Renaming a type

A generated type takes its short class name by default. Override it with `#[TypeScriptName]`
when the short name would collide. An `#[Input]` property defaults to the `Body` interface
(it reads from both body and query); force it into `Query` with
`#[InputMapsToTypeScriptQuery]`. `#[InputMapsToTypeScriptBody]` and
`#[InputMapsToTypeScriptQuery]` apply only to an `#[Input]` property of a top-level request
class; elsewhere, or together, they throw.
