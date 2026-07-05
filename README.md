# Roma 🍅

[![Tests](https://github.com/billisonline/roma/actions/workflows/run-tests.yml/badge.svg)](https://github.com/billisonline/roma/actions/workflows/run-tests.yml)

## Introduction

Roma is a Request Object MApper. It has its own implementation of an object mapper designed to map _all_ aspects of Laravel's `Illuminate\Http\Request` request to a fully type-safe and validated POPO (plain old PHP object). That includes headers, the query string, the body, files, and convenience methods of the request object (e.g., `$request->ajax()`). The goal is that when using a custom Roma request, you should never have to interact with the underlying Laravel request directly.

## Create a request object

Creating a request object is as simple as adding all the properties you want to populate from the request. Validation rules can be added using the `#[Rule]` attribute:

```php
use BYanelli\Roma\Request\Attributes\Rule;

readonly class CreateContactRequest {
    public function __construct(
        #[Rule('max:255')]
        public string $name,

        #[Rule(['email', 'unique:contacts', 'max:255'])]
        public string $email,
    ) {}

    // Constructor promoted properties and class properties can be used interchangeably.
    #[Rule('phone')]
    public string $phone;
}
```

## Use the request object in your controller

Simply inject the request object using the contextual binding attribute:

```php
use BYanelli\Roma\Request\ContextualBinding\Request;
use App\Models\Contact;

class CreateContactController {
    public function __invoke(
        #[Request] CreateContactRequest $request,
    ) {
        Contact::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
        ]);
    }
}
```

## Map headers

Map specific headers to properties using the `#[Header]` attribute, or take advantage of pre-made ones like `#[ContentType]` (a shortcut for `#[Header('Content-Type')]`):

```php
use BYanelli\Roma\Request\Attributes\Header; 
use BYanelli\Roma\Request\Attributes\Headers\ContentType;

readonly class ApiRequest { 
    #[Header('X-API-Key')]
    public string $apiKey;

    #[ContentType]
    public string $contentType;
}
``` 

## Nullable and optional properties

A non-nullable property with no default is required. Give it a default value to make it optional. Making the type nullable (`?T`) also makes it optional: an absent _or_ explicitly-`null` key resolves to `null` (Roma applies Laravel's `nullable` rather than `required`).

```php
class ProductSearchRequest {
    public string $name;       // required

    public int $perPage = 15;  // optional (has a default)

    public ?string $search;    // optional; null when absent or null
}
```

The same holds for nested objects: an absent or null nullable object stays `null` and its children are _not_ required. But a *present* object — even an empty `{}` — is validated, so its required children must be supplied.

```php
readonly class Address {
    public string $city;
}

readonly class OrderRequest {
    public string $name;

    public ?Address $shipTo; // null when absent; when present, `city` is required
}
```

## Require a key to be present but nullable

Use `#[Present]` on a nullable property when the key _must_ appear in the request but is allowed to be `null` — "present but may be null". It adds Laravel's `present` rule, so an omitted key fails validation while an explicit `null` passes.

```php
use BYanelli\Roma\Request\Attributes\Present;

readonly class UpdateNoteRequest {
    public string $id;

    #[Present]
    public ?string $note; // must be sent, but may be null
}
```

## Choose the query string or body

By default a property reads from the merged input bag (query string + body). Use `#[Query]` or `#[Body]` to bind to one specifically — handy when the same key can appear in both.

```php
use BYanelli\Roma\Request\Attributes\Body;
use BYanelli\Roma\Request\Attributes\Query;

readonly class SearchRequest {
    #[Query]
    public int $page;   // always from the query string

    #[Body]
    public string $token; // always from the request body
}
```

## Map request metadata

Access (and optionally validate) request metadata:

```php
use BYanelli\Roma\Request\Attributes\Accessors\Ajax;
use BYanelli\Roma\Request\Attributes\Accessors\Method;

readonly class MetadataRequest {
    #[Ajax(mustBe: true)] // Requires AJAX request
    public bool $isAjax;

    #[Method]
    public string $method;  // GET, POST, etc.
}
```

Roma wraps most of the Laravel request surface with accessor attributes. Group them by what they return:

```php
use BYanelli\Roma\Request\Attributes\Accessors\Ip;
use BYanelli\Roma\Request\Attributes\Accessors\Secure;
use BYanelli\Roma\Request\Attributes\Accessors\Segments;
use BYanelli\Roma\Request\Attributes\Accessors\UserAgent;

readonly class RequestInfo {
    #[Secure]
    public bool $isSecure;

    #[Ip]
    public string $ip;

    #[UserAgent]
    public string $userAgent;

    /** @var array<string> */
    #[Segments]
    public array $segments;
}
```

* **Booleans:** `#[Ajax]`, `#[Secure]`, `#[Pjax]`, `#[Prefetch]`, `#[IsJson]`, `#[ExpectsJson]`, `#[WantsJson]`
* **Strings:** `#[Method]`, `#[Ip]`, `#[UserAgent]`, `#[Url]`, `#[FullUrl]`, `#[Path]`, `#[DecodedPath]`, `#[Root]`, `#[Host]`, `#[SchemeAndHttpHost]`, `#[BearerToken]`, `#[Format]`
* **Arrays:** `#[Ips]`, `#[Segments]`

Every boolean accessor accepts `mustBe` to turn it into a constraint: `#[Secure(mustBe: true)]` requires HTTPS, `#[Ajax(mustBe: false)]` requires a non-AJAX request. Applied bare at the class level, a boolean accessor requires the truthy case (see [Class-Level Constraints](#class-level-constraints)).

## Map to enums

Roma automatically maps values to string-backed, integer-backed, and unit enums:

```php
enum Status: string { 
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Complete = 'complete';
}

enum Priority: int {
    case Low = 1; 
    case Medium = 2; 
    case High = 3;
}

enum Department {
    case CustomerService;
    case Sales;
}

class UpdateTaskRequest {
    public Status $status; 
    public Priority $priority;
    public Department $department;
}
``` 

## Map to files

Type-hint any property with `Illuminate\Http\UploadedFile` and it will be mapped.

```php
use Illuminate\Http\UploadedFile;

class FileRequest {
    public UploadedFile $myFile;
}
```

## Map to nested objects

Type-hint your properties to other POPOs to deserialize complex nested structures from JSON payloads:

```php
class Address { 
    public string $address; 
    public string $city; 
    public State $state;
    public string $zipCode; 
    public Country $country; 
}

class UserRequest { 
    public string $name; 
    public string $email; 
    public Address $address; 
}
``` 

## Compose requests using traits

Share common properties across multiple request classes using traits:

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
    public ?Category $category;
}
```

## Coerce non-string types

Roma handles automatic type conversion for common types:

```php
class OrderRequest { 
    public float $price; // "9.99" → 9.99

    public bool $isGift; // "true" → true

    public \DateTimeInterface $deliveryDate; // "2024-01-01" → DateTime object

    /** @var array<int> */
    public array $itemIds; // ["1", "2", "3"] → [1, 2, 3]
}
``` 

## Class-Level Constraints

Apply validation rules at the class level to enforce global requirements:

```php
use BYanelli\Roma\Request\Attributes\Accessors\Ajax;
use BYanelli\Roma\Request\Attributes\Headers\ContentType;

#[Ajax] // Requires all requests mapped to this class to be AJAX 
#[ContentType(ContentType::APPLICATION_JSON)] // Requires JSON content type
class ApiOnlyRequest { 
    public string $data;
}
```

## Validation error keys

When validation fails, Roma throws Laravel's `ValidationException`. Errors are keyed by a source-prefixed, request-relative name so the caller always knows where the offending value belongs:

* `input.price` — merged input (query + body)
* `query.page` / `body.token` — a `#[Query]` / `#[Body]` property
* `header.X-Flag` — a header, by its real (un-normalized) name
* `request.ajax` — request metadata from an accessor

Nested fields keep their full path, and array elements are indexed:

```php
$e->errors(); // returns:

[
    'input.name'         => ['The input.name field is required.'],
    'input.address.city' => ['The input.address.city field is required.'],
    'input.items.1.code' => ['The input.items.* field must be at least 3 characters.'],
    'request.ajax'       => ['The request.ajax field must be accepted.'],
];
```

## More to come

* Type-safe responses! We want this to be a Request/Response Object MApper
