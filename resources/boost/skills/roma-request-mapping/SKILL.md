---
name: roma-request-mapping
description: >-
  Use when building or editing HTTP input/output mapping with the Roma package
  (byanelli/roma) in a Laravel app. Activate on mentions of Roma, request/response
  objects, typed request DTOs, the #[Request] attribute, source-binding attributes
  (#[Body], #[Query], #[Header], #[RouteParameter], #[Cookie]), header value objects
  (Authorization), self-sourcing metadata enums (Method, ContentType, Scheme),
  #[Guard], #[Rule], Roma TypeScript generation (roma:typescript), or Roma
  Precognition. Do NOT use for plain Laravel FormRequests that are not mapped to a
  typed object, for Eloquent API Resources, or for non-HTTP DTOs.
---

# Roma request & response mapping

Roma (`byanelli/roma`) maps an entire `Illuminate\Http\Request` into a typed, validated
plain PHP object, and maps a plain object back into a JSON response. Prefer it over a
hand-rolled `FormRequest` + manual array-to-DTO whenever an action needs typed input from
the request. A Roma request means the controller never touches `$request->input()`.

Full documentation is online, and available as plain Markdown you can fetch and read
directly: **https://yanelli.dev/docs/roma.md** (human-facing: https://yanelli.dev/docs/roma).
Fetch it when you need detail beyond this skill.

## Defining and injecting a request

A request is a class of typed properties. Attach validation with `#[Rule]`. **Mark the
class `#[Request]` and inject it by type-hint** — a request class is almost always used only
as a request, so this is the default. `#[Request]` also works on the controller parameter.

```php
use BYanelli\Roma\Request\Attributes\Rule;
use BYanelli\Roma\Request\ContextualBinding\Request;

#[Request] // class-level: enables type-hint-only injection + TypeScript auto-detection
readonly class CreateContactRequest {
    public function __construct(
        #[Rule('max:255')] public string $name,
        #[Rule(['email', 'unique:contacts'])] public string $email,
    ) {}
}

class CreateContactController {
    public function __invoke(CreateContactRequest $request) {
        Contact::create(['name' => $request->name, 'email' => $request->email]);
    }
}
```

- Type coercion and enum/nested validation are automatic — a `public int $page` validates
  as an integer without an `integer` rule.
- Required = non-nullable with no default. Optional = has a default OR is nullable (`?T`).
- `#[Present]` on a nullable property requires the key to appear but allows `null`.

## Sources

Default source is the merged input bag (query + body). Bind explicitly:

| Attribute | Source |
|---|---|
| `#[Query]` / `#[Body]` | query string / request body |
| `#[Header('X-Api-Key')]` | a request header |
| `#[RouteParameter]` | a route parameter (name = property unless given) |
| `#[Cookie]` | a cookie (pass a name for dotted cookie names) |
| `Illuminate\Http\UploadedFile` type | a file upload (top-level only) |

## Headers & metadata — prefer the value objects over hand-parsing

```php
use BYanelli\Roma\Request\Values\Authorization;
use BYanelli\Roma\Request\Enums\{Method, ContentType, Scheme};

readonly class ApiRequest {
    public Authorization $auth; // parses the Authorization header; self-locates
    public Method $method;      // Method::Get, ... (metadata enum, self-sourcing)
    public ContentType $type;   // from Content-Type; params stripped before matching
}
```

```php
$request->auth->scheme;               // AuthScheme::Bearer | Basic | Digest
$request->auth->isBearer();
$request->auth->basic()?->username;   // base64-decoded Basic creds; null if not Basic
```

Do not hand-parse a header Roma already models. Accessor attributes cover the rest of the
request surface (`#[Ip]`, `#[UserAgent]`, `#[Segments]`, `#[Ajax]`, `#[Secure]`, …); boolean
accessors take `mustBe:` to become a constraint (`#[Secure(mustBe: true)]`).

## Nested objects

Type a property as another plain object to deserialize nested JSON. A nested object inherits
its location from the parent — **source attributes and metadata enums are top-level only**;
declaring one on a nested property throws. Use `#[Key]` (nested-only) to override a nested
key, e.g. one containing a literal dot.

## Validation extras

- **Dynamic rules:** pass a first-class-callable to `#[Rule]`; Roma resolves it through the
  container at validation time (`#[Rule('string', self::maxLength(...))]`).
- **Guards:** a `#[Guard]` method runs after validation, resolved through the container
  (inject `#[CurrentUser]`, services, …); reject by throwing. Multiple run in order.
- **Class-level constraints:** apply `#[Ajax]`, `#[ContentType(...)]`, etc. at the class
  level to require them of every request.

### Error-key contract (important)

Validation errors are keyed by **source-prefixed, request-relative** names:
`input.price`, `query.page`, `header.X-Flag`, `route.id`, `cookie.session`, `request.ajax`.
Never emit or expect bare field keys — the **only** exception is a precognitive request,
where form-data errors are keyed by the bare posted name for front-end helpers.

## Responses

Extend `Response`, declare typed properties, return it — Roma serializes to JSON
(recursively; enums → `{name,value}` / name, dates → ISO-8601). A property has no implicit
default: unset throws unless marked `#[Optional]` (omit) or given a default. Lift a property
out of the body with `#[Status]` (int → HTTP status) or `#[Header('Name')]`. Use
`IsResponsable` / `IsArrayable` traits when the class can't extend `Response`.

## TypeScript

`php artisan roma:typescript` writes `.d.ts` interfaces (`{Name}Body`/`{Name}Query`/
`{Name}Headers` for requests). Requests are auto-detected by a class-level `#[Request]`;
responses by extending `Response`/using `IsResponsable`. No manual list to maintain.

## Precognition

Add `HandlePrecognitiveRequests` middleware to the route. A precognitive request validates
**form data only** (input/query/body/file); header/cookie/route/metadata rules are skipped,
the object is not constructed, and `#[Guard]`s do not run. Failing → `422` with bare-name
keys (front-end helper shape); passing → empty `204`.

## Don't

- Don't duplicate Roma's validation in the controller, or read `$request->input()` on a
  mapped action.
- Don't hand-parse headers Roma models (`Authorization`, `ContentType`).
- Don't put a source attribute or `UploadedFile` on a nested-object property.
- Don't emit bare (non-source-prefixed) error keys outside precognitive responses.
