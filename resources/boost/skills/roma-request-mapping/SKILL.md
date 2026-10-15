---
name: roma-request-mapping
description: >-
  Use when building or editing HTTP input/output mapping with the Roma package
  (byanelli/roma) in a Laravel app. Activate on mentions of Roma, request/response
  objects, typed request DTOs, the #[Request] attribute, source-binding attributes
  (#[Body], #[Query], #[Header], #[Path], #[Cookie]), header value objects
  (Authorization), self-sourcing metadata enums (Method, ContentType, Scheme),
  #[Guard], #[Rule], Roma TypeScript generation (roma:typescript), or Roma
  Precognition, or Roma JSON Schema for tools (ClassSchema). Do NOT use for plain Laravel FormRequests that are not mapped to a
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

## Namespaces

| Namespace | Contents |
| --- | --- |
| `BYanelli\Roma\Attributes` | `Key`, `Rule`, `Present`, `Guard`, `DateFormat`, `Optional`, `Discriminated`, `DiscriminatorValue`, `AutoDiscriminated`, `Description` |
| `BYanelli\Roma\Http\Attributes` | `Header`, `Status`, `Query`, `Body`, `Cookie`, `Input`, `Path`; `Accessors\*`, `Headers\*` |
| `BYanelli\Roma\Http\Request\Injection` | `Request` (the injection marker) |
| `BYanelli\Roma\Http\Response` | `Response`, `IsResponsable` |
| `BYanelli\Roma\Http\Enums`, `BYanelli\Roma\Http\Values` | `Method`, `ContentType`, `Scheme`, `AuthScheme`; `Authorization`, `BasicCredentials` |
| `BYanelli\Roma\Serialization` | `IsArrayable`, `Profile`, `Serialized` |
| `BYanelli\Roma\JsonSchema` | `ClassSchema` |

## Defining and injecting a request

A request is a class of typed properties. Attach validation with `#[Rule]`. **Mark the
class `#[Request]` and inject it by type-hint** — a request class is almost always used only
as a request, so this is the default. `#[Request]` also works on the controller parameter.

```php
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Http\Request\Injection\Request;

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
- `#[Optional]` is never required. Type a property `T|Missing` to tell an absent key
  (`Missing::instance()`) from `null`; the type alone makes it optional, so `#[Optional]` is
  redundant there. `Missing` alone (`Missing`, `?Missing`) throws; write `string|Missing`. A
  non-promoted `#[Optional]` property without `Missing` stays unset when absent. An
  `#[Optional]` parameter that is not nullable, has no `Missing` and no default throws. The serializer omits `Missing` values and TypeScript emits `x?: T`.
- `#[DateFormat('Y-m-d')]` on a date property requires input in that format (`date_format`
  rule), parses it with `createFromFormat`, and serializes it in the same format. It covers
  the dates in an array, map or shape property, never those inside a nested object, and
  throws on a type with no date.
- An `array` property needs its element type in PHPDoc (`@var` on a property, `@param` on
  a constructor parameter): `array<T>`, `list<T>`, `array<string, T>` / `array<int, T>`, or
  an array shape such as `array{min: int, max?: int}`. `list<T>` also requires sequential
  keys. These nest, e.g. `array<string, list<array{id: int}>>`. Class names in PHPDoc resolve
  through the declaring file's `use` imports (including aliases and group imports) and
  namespace, as in PHP code; a name that resolves to no class throws `UnresolvableClassName`.

## Sources

Default source is the merged input bag (query + body). Bind explicitly:

| Attribute | Source |
|---|---|
| `#[Query]` / `#[Body]` | query string / request body |
| `#[Header('X-Api-Key')]` | a request header |
| `#[Path]` | a route parameter (name = property unless given) |
| `#[Cookie]` | a cookie (pass a name for dotted cookie names) |
| `Illuminate\Http\UploadedFile` type | a file upload (top-level only) |

## Headers & metadata — prefer the value objects over hand-parsing

```php
use BYanelli\Roma\Http\Values\Authorization;
use BYanelli\Roma\Http\Enums\{Method, ContentType, Scheme};

readonly class ApiRequest {
    public Authorization $auth; // parses the Authorization header; self-locates
    public Method $method;      // Method::Get, Method::Query, ... (metadata enum, self-sourcing)
    public ContentType $type;   // from Content-Type; params stripped before matching
}
```

```php
$request->auth->scheme;               // AuthScheme::Bearer | Basic | Digest
$request->auth->isBearer();
$request->auth->basic()?->username;   // base64-decoded Basic creds; null if not Basic
```

Do not hand-parse a header Roma already models. Accessor attributes cover the rest of the
request surface (`#[Ip]`, `#[UserAgent]`, `#[Segments]`, `#[Ajax]`, `#[Secure]`, `#[Content]` for the
raw body, …); boolean
accessors take `mustBe:` to become a constraint (`#[Secure(mustBe: true)]`).

## Nested objects

Type a property as another plain object to deserialize nested JSON. A nested object inherits
its location from the parent — **source attributes and metadata enums are top-level only**;
declaring one on a nested property throws. Use `#[Key]` to override a nested key, e.g. one
containing a literal dot; on a top-level property it sets the key within the source
(`#[Key('page_num')]` reads `input.page_num`). A property may get its key from only one
attribute, so `#[Query('a')]` with `#[Key('b')]` throws. The serializer writes the property
under the same key, from `#[Key]` or a source attribute. A nested class may contain itself
(`list<Comment>` in `Comment`, `?Node $parent`); its rules are built from the data, and
errors keep full keys such as `input.replies.0.replies.1.body`.

A property that can be one of several classes is a discriminated union: type it as an
interface, an abstract class or a native union, and list the classes with
`#[Discriminated([Card::class, BankAccount::class])]` on the interface/abstract class or on
the property (required for `Card|Voucher`; on a concrete class it throws). The wire value under `type` (or `key:`) is the
short class name or `#[DiscriminatorValue('bank')]`; member classes must not declare a
property under the key. Unknown values fail validation at `input.payment.type`. The
serializer writes the key only through a discriminated property, and TypeScript emits
`({ type: 'Card' } & Card) | ...`.

`#[AutoDiscriminated]` (class-level only) on an interface or abstract class makes every
concrete implementation in the `roma.discover` directories a member, with the same rules.
Members come from discovery, so a production deploy must run `php artisan optimize` or
`php artisan roma:cache` after a member is added: while `bootstrap/cache/roma.php` exists it
is the only source, even if stale. `roma:clear` deletes it. Prefer `#[Discriminated]` with
an explicit list unless the member list changes often.

## Validation extras

- **Dynamic rules:** pass a first-class-callable to `#[Rule]`; Roma resolves it through the
  container at validation time (`#[Rule('string', self::maxLength(...))]`).
- **Guards:** a `#[Guard]` method runs after validation, resolved through the container
  (inject `#[CurrentUser]`, services, …); reject by throwing. Multiple run in order.
- **Class-level constraints:** apply `#[Ajax]`, `#[ContentType(...)]`, etc. at the class
  level to require them of every request.

### Error-key contract (important)

Validation errors are keyed by **source-prefixed, request-relative** names:
`input.price`, `query.page`, `header.X-Flag`, `path.id`, `cookie.session`, `request.ajax`.
Never emit or expect bare field keys — the **only** exception is a precognitive request,
where form-data errors are keyed by the bare posted name for front-end helpers.

## Responses

Extend `Response`, declare typed properties, return it — Roma serializes to JSON
(recursively; enums → `{name,value}` / name, dates → ISO-8601). A property has no implicit
default: unset throws unless marked `#[Optional]` (omit) or given a default. Lift a property
out of the body with `#[Status]` (a non-nullable `int` → HTTP status; never on a request
class) or `#[Header('Name')]`, on the top-level class only (in a nested object they throw). Use
`IsResponsable` / `IsArrayable` traits when the class can't extend `Response`. Nested plain
objects don't need the traits. For runtime values, override the public
`responseStatus(Serialized $serialized)` / `responseHeaders(Serialized $serialized)`, or
implement `CustomizesSerialization` for date formats. Prefer get-only hooked properties for
computed values (they reach TypeScript, and `#[Rule]`, `#[Present]` or a source attribute on
one throws); an overridden `toArray(?Profile $profile = null)` is
the escape hatch and should pass `$profile` to `Serializer::toArray($this, $profile)`; a
`Response`'s HTTP body uses the override. `Serializer::toArray($obj, $profile)` /
`Serializer::serialize()` (facade `BYanelli\Roma\Facades\Serializer`) serialize any object
outside a response.

## TypeScript

`php artisan roma:typescript` writes `.d.ts` interfaces (`{Name}Body`/`{Name}Query`/
`{Name}Headers` for requests). Requests are auto-detected by a class-level `#[Request]`;
responses by extending `Response`/using `IsResponsable`. No manual list to maintain.
`#[Description('...')]` on a class or property becomes a `/** ... */` doc comment.

## JSON Schema

`ClassSchema::properties(SearchInput::class)` returns `array<string, Illuminate\JsonSchema\Types\Type>`
built at runtime from the class: return it from an MCP or AI SDK tool's
`schema(JsonSchema $schema)` instead of writing the schema by hand. `ClassSchema::object()`
returns one `ObjectType`. Required/nullable/defaults follow mapping, `#[Description]` sets
`description`, discriminated unions become `anyOf`, and `#[Rule]`s such as `max`, `in`,
`email` and `regex` add keywords; other rules are still enforced when mapping. A class that
contains itself, a file upload or an HTTP source attribute throws. `array<string, T>` maps
are an `object` without a value schema.

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
- Don't combine attributes that contradict or override each other: two sources, two keys,
  `#[Key]` with `#[Status]`, `#[Status]` with `#[Header]`, `#[Optional]` with `#[Present]`, or
  a source attribute on an `UploadedFile`. Each throws when the definition is built.
- Don't type a property as an interface, abstract class or union of classes without
  `#[Discriminated]`; it throws.
- Don't emit bare (non-source-prefixed) error keys outside precognitive responses.
