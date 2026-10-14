---
extends: _layouts.docs.roma
section: content
title: Nested Objects
package: roma
version: v1
weight: 5
group: Requests
---

# Nested objects

Type-hint a property as another plain object to deserialize nested JSON structures:

```php
class Address {
    public string $address;
    public string $city;
    public State $state;
    public string $zipCode;
}

class UserRequest {
    public string $name;
    public string $email;
    public Address $address;
}
```

A nested object inherits its location from the parent property, so source attributes
(`#[Input]`, `#[Query]`, `#[Body]`, `#[Header]`, `#[RouteParameter]`, `#[Cookie]`,
accessors) and self-sourcing metadata enums are only valid on **top-level** request
classes. Declaring one on a nested property throws — its data always comes from within the
parent's slice.

## Nullable nested objects

An absent or null nullable object stays `null`, and its children are _not_ required. But a
_present_ object — even an empty `{}` — is validated, so its required children must be
supplied:

```php
readonly class Address {
    public string $city;
}

readonly class OrderRequest {
    public string $name;
    public ?Address $shipTo; // null when absent; when present, `city` is required
}
```

## Classes that contain themselves

A nested class may contain itself, directly or through other classes, for trees such as
comment threads, a parent chain or folders of discriminated entries:

```php
readonly class Comment {
    /**
     * @param  list<Comment>  $replies
     */
    public function __construct(
        public string $body,
        public array $replies = [],
    ) {}
}

readonly class ThreadRequest {
    /**
     * @param  list<Comment>  $replies
     */
    public function __construct(public array $replies) {}
}
```

Validation rules for such a structure are built from the data, as deep as it goes, so an
error keeps its full key: `input.replies.0.replies.1.body`. TypeScript refers to the
interface by name (`replies?: Comment[]`).

## Overriding a nested key with `#[Key]`

A nested property can't declare a source, so to override its key — for example when the
client field name contains a literal dot that can't be a PHP property name — use `#[Key]`:

```php
use BYanelli\Roma\Attributes\Key;

class Meta {
    #[Key('created.at')] // reads the "created.at" field from the parent's slice
    public string $createdAt;
}

class ArticleRequest {
    public string $title;
    public Meta $meta;
}
```

## Discriminated unions

A property whose value can be one of several classes is typed as an interface, an abstract
class or a native union, and declares those classes with `#[Discriminated]`. The value under
the discriminator key (default `type`) selects the class:

```php
use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\DiscriminatorValue;

#[Discriminated([Card::class, BankAccount::class])]
interface PaymentMethod {}

readonly class Card implements PaymentMethod {
    public function __construct(public string $last4) {}
}

#[DiscriminatorValue('bank')] // the default is the short class name, "BankAccount"
readonly class BankAccount implements PaymentMethod {
    public function __construct(public string $iban) {}
}

readonly class CheckoutRequest {
    /**
     * @param  list<PaymentMethod>  $fallbacks
     */
    public function __construct(
        public PaymentMethod $payment,          // {"type": "Card", "last4": "4242"}
        public array $fallbacks = [],
        #[Discriminated([Card::class, Voucher::class])]
        public Card|Voucher|null $refundTo = null,
    ) {}
}
```

- The attribute goes on the interface or abstract class, or on the property. It is required
  on a native union of classes, and on a property typed as an interface or abstract class it
  replaces the class-level attribute. On an array property it applies to the elements, so
  `list<Card|Voucher>` works with a property-level attribute. Change the key with
  `key: 'kind'`.
- The rest of the object maps into the selected class, with the usual coercion and
  validation. The key itself is not passed to the class, and a member class may not declare
  a property under it.
- An unknown or missing value is a validation error at the key: `input.payment.type` "The
  input.payment.type field must be one of: Card, bank."
- Elements of an array or map can be different classes, so their errors have concrete keys
  such as `input.fallbacks.1.iban`.
- The class list is checked when the definition is built: each class must be concrete and
  extend the interface or abstract class, a native union's list must equal its classes, and
  two classes may not have the same value. A property typed as an interface, an abstract
  class or a union of classes without `#[Discriminated]` throws. A union of scalars such as
  `int|string` is not affected.
