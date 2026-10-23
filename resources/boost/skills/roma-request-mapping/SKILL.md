---
name: roma-request-mapping
description: >-
  Use when building or editing HTTP input/output mapping with the Roma package
  (byanelli/roma) in a Laravel app. Activate on mentions of Roma, request/response
  objects, typed request DTOs, the #[Request] attribute, source-binding attributes
  (#[Body], #[Query], #[Header], #[Path], #[Cookie]), header value objects
  (Authorization), self-sourcing metadata enums (Method, ContentType, Scheme),
  #[Guard], #[Rule], Roma TypeScript generation (roma:typescript), or Roma
  Precognition, Roma JSON Schema for tools (ClassSchema), Roma config objects
  (#[ConfigObject], #[ConfigKey], roma:config:validate), or Roma's typed HTTP client
  (TypedFactory, sendRequest(), RomaHttp, #[Get]/#[Post], #[Responses]), or Roma
  Eloquent casts (AsMappedObject, StringBacked). Do NOT use for plain Laravel FormRequests that are not mapped to a
  typed object, for Eloquent API Resources, or for other DTOs that Roma doesn't map.
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
| `BYanelli\Roma\Attributes` | `Key`, `SnakeCaseKeys`, `Rule`, `RulePerItem`, `RequiredOnCreate`, `IgnoreIf`, `IgnoreIfEmpty`, `RejectUnknownKeys`, `Message`, `MapUsing`, `Limit`, `RejectNull`, `RejectNumbers`, `SerializeUsing`, `Present`, `Guard`, `DateFormat`, `Optional`, `Discriminated`, `DiscriminatorValue`, `AutoDiscriminated`, `Description` |
| `BYanelli\Roma\Http\Attributes` | `Header`, `Status`, `Query`, `Body`, `Cookie`, `Input`, `Path`; `Accessors\*`, `Headers\*` |
| `BYanelli\Roma\Http\Request\Injection` | `Request` (the injection marker) |
| `BYanelli\Roma\Http\Request` | `HandlesFailedValidation` |
| `BYanelli\Roma\Http\Response` | `Response`, `IsResponsable` |
| `BYanelli\Roma\Http\Enums`, `BYanelli\Roma\Http\Values` | `Method`, `ContentType`, `Scheme`, `AuthScheme`; `Authorization`, `BasicCredentials` |
| `BYanelli\Roma\Serialization` | `IsArrayable`, `Profile`, `Serialized` |
| `BYanelli\Roma\Facades` | `Mapper` (`fromArray()`, `fromConfig()`), `Serializer`, `RomaHttp` |
| `BYanelli\Roma\JsonSchema` | `ClassSchema` |
| `BYanelli\Roma\Config` | `ConfigObject`, `ConfigKey`, `ConfigValidationException` |
| `BYanelli\Roma\Eloquent` | `AsMappedObject`, `StringBacked`, `InvalidAttributeValue` |
| `BYanelli\Roma\Http\Client` | `Contracts\TypedFactory`, `TypedPendingRequest`; `Attributes\{Get, Head, Post, Put, Patch, Delete, Url, AsForm, AsMultipart, Responses}`; `Exceptions\{MalformedResponseException, UnmatchedResponseException}` |

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
        #[Rule('max:255')]
        public string $name,
        #[Rule(['email', 'unique:contacts'])]
        public string $email,
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
- A `null` for a non-nullable property with a default takes the default; `#[RejectNull]`
  (property or class) makes it fail instead. Nullable properties keep null. `""` isn't null,
  but Laravel's `ConvertEmptyStringsToNull` usually makes it one.
- A `bool` reads input as `$request->boolean()` does: "1"/"true"/"on"/"yes" are true, any
  other scalar ("0", "no", "Y", "") is false; arrays fail validation.
- A `string` accepts a JSON number as its string form (35201 → "35201"); bools still fail.
  `#[RejectNumbers]` (property or class) makes numbers fail instead.
- `#[Present]` on a nullable property requires the key to appear but allows `null`. With a
  default value it throws, since the default is never used.
- `#[Optional]` is never required. Type a property `T|Missing` to tell an absent key
  (`Missing::instance()`) from `null`; the type alone makes it optional, so `#[Optional]` is
  redundant there. `Missing` alone (`Missing`, `?Missing`) throws; write `string|Missing`. A
  non-promoted `#[Optional]` property without `Missing` stays unset when absent. An
  `#[Optional]` parameter that is not nullable, has no `Missing` and no default throws. The serializer omits `Missing` values and TypeScript emits `x?: T`.
- `#[RequiredOnCreate]` on an optional property (usually `T|Missing`) adds `required` on a
  POST (`Request::isMethod('POST')`, so `_method=PUT` is a PUT); on other methods and in
  `Mapper::fromArray()` it stays optional. `#[Message(required: ...)]` applies. On an
  always-required property it throws.
- `#[IgnoreIfEmpty]` treats a raw `''` or `[]` (JSON `{}` too) as absent: `Missing`, not
  validated, not serialized. `null`, `"0"`, `0`, `false` are kept. `#[IgnoreIf(callable)]`
  (repeatable) does the same when the callable returns true for the raw value. Both need
  `Missing` in the type, else they throw.
- `#[RejectUnknownKeys]` (class only, inherited by subclasses, also as a nested object) makes
  keys no property maps from fail with Laravel's `prohibited` message, keyed by full path
  (`input.config.k`); `message:` replaces it. Requests check only top-level body keys (not
  query, headers, `_method`, `_token`); nested objects are always checked. Ignored and
  virtual properties' keys and union discriminators are known.
- `#[DateFormat('Y-m-d')]` on a date property requires input in that format (`date_format`
  rule), parses it with `createFromFormat`, and serializes it in the same format. It covers
  the dates in an array, map or shape property, never those inside a nested object, and
  throws on a type with no date, including `mixed`, no type and an undocumented `array`.
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
| `#[Cookie]` | a cookie (pass a name for cookie names with dots) |
| `Illuminate\Http\UploadedFile` type | a file upload (top-level only) |

`#[SnakeCaseKeys]` on a class keys its properties by the snake_case of their names
(`$firstName` ↔ `first_name`) everywhere; prefer it to a `#[Key]` on each property.

A dot in a key nests in input, query, body, plain arrays and config:
`#[Key('error.message')]` reads `{"error": {"message": ...}}`, its errors are under
`input.error.message`, and the serializer, TypeScript and JSON Schema write it as nested
objects. Escape a literal dot Laravel's way (`#[Key('created\.at')]`). Header, cookie and
`#[Path]` names are flat, so their dots are part of the name. A key inside another key of the
same source (`error` beside `error.message`) throws.

A custom source extends `BYanelli\Roma\Data\Source` with `@extends Source<Request>`
(`getKey()`, the error-key prefix; `inputType()`, here `Request::class`; `read(object $input)`,
which returns the source's values; `nestsKeys()`, false for flat data) and is used through an
attribute that implements `SourceAttribute`. Only the sources a class uses are read, and each one is validated on its
own.

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
declaring one on a nested property throws. Use `#[Key]` to override a nested key (a dot
nests there too); on a top-level property it sets the key within the source
(`#[Key('page_num')]` reads `input.page_num`). A property may get its key from only one
attribute, so `#[Query('a')]` with `#[Key('b')]` throws, as does an empty key
(`#[Key('')]`) or two properties on the same key of one source or nested class. The serializer writes the property under the same key, from `#[Key]` or a
source attribute. A nested class may contain itself (`list<Comment>` in `Comment`,
`?Node $parent`); its rules are built from the data, and errors keep full keys such as
`input.replies.0.replies.1.body`. A non-empty list for a nested object fails ("must be an
object", `#[Message(object: ...)]`); an empty one maps as `{}`.

A property that can be one of several classes is a discriminated union: type it as an
interface, an abstract class or a native union, and list the classes with
`#[Discriminated([Card::class, BankAccount::class])]` on the interface/abstract class or on
the property (required for `Card|Voucher`; on a concrete class it throws). The wire value under `type` (or `key:`) is the
short class name or `#[DiscriminatorValue('bank')]`; member classes must not declare a
property under the key. Unknown values fail validation at `input.payment.type`. The
serializer writes the key only through a discriminated property, and TypeScript emits
`({ type: 'Card' } & Card) | ...`.

`#[AutoDiscriminated]` (class-level only; on a property it throws) on an interface or
abstract class makes all concrete implementations in the `roma.discover` directories
members, with the same rules.
Members come from discovery, so a production deploy must run `php artisan optimize` or
`php artisan roma:cache` after a member is added: while `bootstrap/cache/roma.php` exists it
is the only source, even if stale. `roma:clear` deletes it. Prefer `#[Discriminated]` with
an explicit list unless the member list changes often.

## Validation extras

- **Dynamic rules:** pass a first-class-callable to `#[Rule]`; Roma resolves it through the
  container at validation time (`#[Rule('string', self::maxLength(...))]`).
- **Item rules:** `#[RulePerItem('email')]` on an array property validates each item, with
  errors keyed per item (`input.emails.1`). Same rule forms as `#[Rule]`; the property's
  `#[Message]` covers its items; throws on a non-array property.
- **Cross-field rules** (`required_if:type,card`, `same:password`) use keys relative to the
  property's source, without the prefix, and see only that source's data. Compare values
  from different sources in a `#[Guard]`.
- **Guards:** a `#[Guard]` method runs after validation, resolved through the container
  (inject `#[CurrentUser]`, services, …); reject by throwing. Multiple run in order.
- **Custom messages:** `#[Message(required: 'Send a name.')]` sets a property's message for a
  Laravel rule; a message without a rule name covers all of the property's rules.
- **Transforms:** `#[MapUsing(strtolower(...))]` changes a value after validation; a
  `static function (Request $request)` callable computes the property from the request
  instead (not read from input, not validated; must be the first transform; top-level only).
  `#[SerializeUsing(...)]` changes the serialized value; a user callable with a second
  parameter receives the object. Attributes can be static closures or first-class callables,
  not arrow functions. `#[Limit(200)]` cuts a string with `Str::limit()` (appends `...`
  unless `end: ''`). Implement `MapTransform` / `SerializeTransform` for reusable ones.
- **Custom error responses:** implement `HandlesFailedValidation` and return a response (or a
  Roma `Response`) from `static failedValidation(ValidationException $e)`, as with
  `FormRequest::failedValidation()`. It also receives a guard's `ValidationException`.
- **Class-level constraints:** apply `#[Ajax]`, `#[ContentType(...)]`, etc. at the class
  level to require them of all requests.

### Error-key contract (important)

Validation errors are keyed by **source-prefixed, request-relative** names:
`input.price`, `query.page`, `header.X-Flag`, `path.id`, `cookie.session`, `request.ajax`.
Never emit or expect bare field keys, with two exceptions: a precognitive request, where
form-data errors are keyed by the bare posted name for front-end helpers, and an app that
sets `roma.source_prefixed_errors` to `false`, where input, query, body and file errors are
keyed and worded as Laravel's validator does (`file_name`, "The file name field is required.").

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
computed values (they reach TypeScript, and `#[Rule]`, `#[Present]`, `#[Input]`, `#[Cookie]` or
an accessor on one throws); an overridden `toArray(?Profile $profile = null)` is
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
are an `object` without a value schema; the description gives the value type
("Values: Address"). Map the tool's arguments with
`Mapper::fromArray(SearchInput::class, $arguments)`, which throws
`MappingValidationException` on invalid input.

## Config objects

A class with `#[ConfigObject('services.scrapfly')]` reads its properties from the config
repository (never `env()`), coerced and validated like a request. Inject it by type-hint.

```php
#[ConfigObject('services.scrapfly')]
readonly class ScrapflyConfig {
    public function __construct(
        #[Key('key')]
        #[\SensitiveParameter]
        public string $apiKey,                                      // services.scrapfly.key
        public bool $renderJs = false,                              // services.scrapfly.render_js
        #[ConfigKey('articles.scrapfly_timeout')]
        #[Rule('min:1')]
        public int $timeout = 180,
    ) {}
}
```

- A property without a key attribute reads the snake_case of its name (config only).
  `#[Key('a.b')]` is relative to the prefix and a dot nests; `#[ConfigKey]` is absolute and
  top-level only. Nested objects and arrays read from under their property's key.
- Missing, `null` and blank strings (`KEY=` in .env) are missing: the default applies, a
  nullable property gets `null`, a required one fails "required".
- Remove `(int)`/`(bool)` casts from config files so Roma validates the raw strings.
- Errors use full config keys ("The services.scrapfly.key field is required.") and throw
  `ConfigValidationException` on resolve; its message lists the class and all errors, with
  `#[\SensitiveParameter]` values replaced by `[redacted]`.
- The binding isn't shared, so `config([...])` in a test applies; Laravel's `#[Singleton]`
  on the class shares it. `#[ConfigObject('...')]` on a constructor parameter sets a prefix
  for that parameter, so one class (e.g. credentials) serves several services.
  `Mapper::fromConfig(Foo::class, ?$prefix)` maps by hand.
- Value types (also in requests and arrays): `#[Delimited(',')]` on an array splits a string
  (`"a, b"`), trimming items and dropping empty ones; `CarbonInterval` accepts `"90 seconds"`
  or `"PT90S"`, and with `#[Unit('seconds')]` a number; `BYanelli\Roma\Values\ByteSize`
  accepts `"10MB"` (1000-based), `"512KiB"` (1024-based) or an int of bytes (`->bytes`).
- `php artisan roma:config:validate` checks the classes with a class-level `#[ConfigObject]`
  in `roma.discover` and exits 1 on failure. Run it in CI and in the deploy script:
  `optimize` runs it too but ignores failures. Classes used only with parameter-level
  prefixes aren't checked.

## Eloquent casts

Cast a model attribute to a class with `AsMappedObject::of()` in the model's `casts()`
method, instead of a hand-written cast or the `array` cast plus manual mapping:

```php
protected function casts(): array
{
    return [
        'settings' => AsMappedObject::of(Settings::class),                          // JSON object column
        'config'   => AsMappedObject::of(FeedConfig::class, defaultWhenNull: true), // null column reads as defaults
        'postcode' => AsMappedObject::of(Postcode::class, nullWhenInvalid: true),   // string column
    ];
}
```

- Any class is stored as a JSON object: read and set arrays are mapped with
  `Mapper::fromArray()` (invalid arrays throw `MappingValidationException`), objects are
  stored and serialized with `Profile::storage()`. Use `#[SnakeCaseKeys]` for snake_case JSON.
- A class implementing `StringBacked` (`static parse(string): ?static`, null when invalid,
  and `toString()`) is stored as a string. A set string is stored as given (it must parse
  unless `nullWhenInvalid`) and `toArray()` writes the stored string.
- Null reads as null; `defaultWhenNull` (JSON only) reads it as the class mapped from `[]`
  and leaves the column null. An unreadable stored value throws `InvalidAttributeValue` (an
  `UnexpectedValueException` with the model class, key and attribute); `nullWhenInvalid`
  reads it as null.
- Object caching is off, so reading never rewrites the stored value; assign a new object to
  change it. In a `$casts` property write `AsMappedObject::class.':'.Settings::class`, with
  options `,default-when-null` / `,null-when-invalid`.

## HTTP client

For a third-party API, inject `BYanelli\Roma\Http\Client\Contracts\TypedFactory` (or use the
`RomaHttp` facade). It wraps the container's `Http` factory, so `Http::fake()`,
`preventStrayRequests()`, middleware and events apply. Configuring methods (`baseUrl()`,
`withToken()`, `retry()`, ...) return a `TypedPendingRequest`; `sendRequest($request,
[Video::class, NotFound::class], url: null)` sends and maps, and PHPStan infers
`Video|NotFound` from that list. `withResponses(X::class)` adds classes, such as an SDK's
fallback exception, for all calls on that pending request.

```php
#[Get('/videos/{id}')]                                     // or #[Post('/path')], #[Put], ...
#[Responses(Video::class, NotFound::class, SubscriberOnlyException::class)]
final readonly class GetVideo {
    public function __construct(
        public string $id,                                 // fills {id} by name
        #[Query]
        public bool $full = false,                         // ?full=false
        #[Header('X-Request-Id')]
        public ?string $requestId = null,
    ) {}
}

#[Status(404)]                                             // a code, '4xx' or 'default'
final readonly class NotFound {}                           // returned

#[Status(403)]
class SubscriberOnlyException extends RequestException {   // thrown
    public function __construct(
        Response $response,
        #[Key('error.message')]
        public readonly string $reason,
    ) {
        parent::__construct($response);
    }
}
```

- Every request property needs `#[Body]`, `#[Query]`, `#[Header]`, `#[Path]` or `#[Url]`, or a
  name in the path template; otherwise it throws. No `#[Body]` on GET/HEAD. JSON by default;
  `#[AsForm]`, `#[AsMultipart]`, or an `UploadedFile` property (multipart). Sensitive values
  are sent.
- Response classes map from the JSON body (dots nest), `#[Header]` and a `#[Status]` int
  property. A class without class-level `#[Status]` goes with 2xx. Exact code beats range
  beats `'default'`. Lists merge by status, most specific first: `sendRequest()` argument,
  `#[Responses]` on the class, on its parents, then `withResponses()`; two classes with one
  status in one list throw. `Illuminate\Http\Client\Response::class` returns the raw response.
- `Throwable` classes are thrown, others returned. Constructor parameters typed `Response`,
  `Throwable`, `Exception` or `RequestException` are injected (the response; Laravel's
  exception, for `previous`). Extend `RequestException`; exception classes are built through
  their constructor only. Use `#[Guard]` for a 2xx with an error body.
- Laravel throws first (`throw()` is called), so `retry()` works as usual. An unmatched
  status ≥ 400 rethrows Laravel's exception unchanged; below 400 throws
  `UnmatchedResponseException`. A `'default'` fallback that fails to map rethrows the original;
  any other mapping failure throws `MalformedResponseException` (status, errors, start of
  body). Messages never contain the URL, and sensitive values are redacted. Give fallback
  properties defaults.
- Tests: `RomaHttp::fake([GetVideo::class => new NotFound])` (objects are serialized with
  their status; closures typed `fn (GetVideo $r)` get the request object; URL keys work as in
  `Http::fake()`), `RomaHttp::response($obj, 500)`, `RomaHttp::assertSent(fn (GetVideo $r) =>
  ...)`, `assertNotSent()`, `sent(GetVideo::class)`. Keep contract tests on recorded JSON
  through `Http::fake()`.
- Not supported: async requests and pools.

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
- Don't emit bare (non-source-prefixed) error keys outside precognitive responses, unless
  `roma.source_prefixed_errors` is `false`.
