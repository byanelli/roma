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

## Overriding a nested key with `#[Key]`

To override a nested property's key — for example when the client field name contains a
literal dot that can't be a PHP property name — use `#[Key]`. It is nested-only (top-level
properties pass the key to their source attribute instead, e.g. `#[Body('a.b')]`):

```php
use BYanelli\Roma\Request\Attributes\Key;

class Meta {
    #[Key('created.at')] // reads the "created.at" field from the parent's slice
    public string $createdAt;
}

class ArticleRequest {
    public string $title;
    public Meta $meta;
}
```
