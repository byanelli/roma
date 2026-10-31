# Roma 🍅

[![Tests](https://github.com/byanelli/roma/actions/workflows/run-tests.yml/badge.svg)](https://github.com/byanelli/roma/actions/workflows/run-tests.yml)
[![PHPStan](https://github.com/byanelli/roma/actions/workflows/phpstan.yml/badge.svg)](https://github.com/byanelli/roma/actions/workflows/phpstan.yml)
[![Pint](https://github.com/byanelli/roma/actions/workflows/pint.yml/badge.svg)](https://github.com/byanelli/roma/actions/workflows/pint.yml)
[![Coverage](https://raw.githubusercontent.com/byanelli/roma/badges/coverage.svg)](https://github.com/byanelli/roma/actions/workflows/coverage.yml)

Roma is an object mapper for Laravel. It maps data into type-safe, validated plain
PHP objects, and serializes objects back out:

- **Requests.** The _entire_ `Illuminate\Http\Request` — body, query string, headers, route
  parameters, cookies, files, and convenience methods like `$request->ajax()`. A Roma
  request is a type-safe `FormRequest` and DTO in one, so your controller never has to touch
  the underlying Laravel request.
- **Responses.** Typed response objects become JSON, with properties that can set the
  status code or headers.
- **APIs you call.** Request objects sent through Laravel's HTTP client, with the response
  mapped onto a class chosen by its status.
- **Config.** Values from Laravel's config repository. A missing API key is reported with
  its config key when the object is used, instead of surfacing later as a 401.
- **Plain arrays**, such as tool input or a stored value.

📚 **Full documentation: [yanelli.dev/docs/roma](https://yanelli.dev/docs/roma)**

## Installation

```bash
composer require byanelli/roma
```

## At a glance

Define a request as a typed class, mark it `#[Request]`, and type-hint it in your
controller. Roma maps and validates it before your action runs:

```php
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Http\Request\Injection\Request;

#[Request]
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
        Contact::create([
            'name'  => $request->name,
            'email' => $request->email,
        ]);
    }
}
```

A config object is a class marked `#[ConfigObject]`. Inject it anywhere:

```php
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Config\ConfigObject;

#[ConfigObject('services.scrapfly')]
readonly class ScrapflyConfig {
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,             // services.scrapfly.api_key, required

        #[Rule('min:1')]
        public int $timeout = 180,         // services.scrapfly.timeout
    ) {}
}
```

## Highlights

- **Map from anywhere in the request.** Bind properties to the body, query, headers, route
  parameters, or cookies with `#[Body]`, `#[Query]`, `#[Header]`, `#[Path]`,
  `#[Cookie]`; the default is the merged input bag. `#[SnakeCaseKeys]` reads `$firstName`
  from `first_name`. A custom source can read anything else
  in the request, such as attributes set by middleware.
- **Typed and validated.** Automatic coercion (strings → `int` / `float` / `bool` / enum /
  date / `UploadedFile`, with `bool` read as `$request->boolean()` reads it, a JSON number
  accepted for a `string`, and `null` taking a property's default), validation via `#[Rule]` and `#[RulePerItem]` for array items, `#[RequiredOnCreate]` for one class that serves POST and PUT, `#[IgnoreIfEmpty]` for a `{}` that means not sent, `#[RejectUnknownKeys]` for strict input, nested objects, discriminated unions
  (`#[Discriminated]`), and `#[Guard]` authorization methods. `#[Message]` sets a property's
  messages, and `HandlesFailedValidation` replaces the 422 with a response of your own.
- **Transforms.** `#[MapFromValue(strtolower(...))]` changes a value after validation,
  `#[MapFromInput]` computes a property from the whole input, such as the request, and
  `#[Limit(200)]` cuts a string. `#[SerializeUsing]` changes a value on the way out, optionally with the object.
- **Header value objects.** Type a property as `Authorization` to get the scheme +
  credentials (with `->basic()` / `->isBearer()`), or use `#[ContentType]` and the `Method`
  / `Scheme` / `ContentType` metadata enums.
- **Response objects.** Return a typed `Response` object and Roma serializes it to JSON,
  with properties liftable to the status code or headers. The `Serializer` facade serializes any
  object, with profiles for responses, outgoing requests and storage.
- **Typed HTTP client.** Send a request object with `$http->sendRequest(new GetVideo($id))`:
  `#[Get('/videos/{id}')]` sets the method and path, and `#[Query]`, `#[Body]`, `#[Header]` and
  `#[Path]` say where each property goes. The response maps onto the class for its status
  (`#[Status(404)]`, `'4xx'`, `'default'`), exception classes are thrown, and Laravel's
  `retry()`, `Http::fake()` and events still apply. Typed fakes and assertions match on the
  request class.
- **Config objects.** Mark a class `#[ConfigObject('services.scrapfly')]` and type-hint it.
  A property's key is the snake_case of its name under the prefix, and values get the same
  coercion and `#[Rule]` validation as a request. Injected objects are mapped when first used, and
  `php artisan roma:config:validate` checks them all in CI or before a deploy.
- **Lists, durations and sizes.** `#[Delimited]` splits `"a, b, c"` into an array,
  `CarbonInterval` accepts `"90 seconds"` (or a number with `#[Unit('seconds')]`), and
  `ByteSize` accepts `"25MB"`. They work in requests and config alike.
- **Secrets stay out of output.** Properties marked `#[\SensitiveParameter]` are left out of
  responses, `toSafeArray()` and validation messages. Stored data keeps them so it maps back,
  and the HTTP client sends them and redacts them from its exception messages.
- **Plain arrays too.** `Mapper::fromArray(Settings::class, $array)` maps and validates an
  array, such as tool input or a stored value, onto the same classes.
- **Objects you construct.** `new JobPosting(...) |> Mapper::validate(...)` checks an object
  built with `new` against its rules, so PHPStan checks the constructor call and Roma still
  checks `max:255` and number ranges.
- **Eloquent casts.** `'settings' => Mapped::to(Settings::class)` stores an attribute as a
  JSON object, mapped and validated when it is read or set from an array. A value object that implements
  `StringBacked` (`parse()` and `toString()`) is stored as a string instead, such as a postcode.
- **Models from objects.** `Contact::createFromObject($request)`, `updateFromObject()` and
  `upsertManyFromObjects()` store a mapped object in a model. With
  `Mapper::requireValidation()` on, they throw for an object that Roma didn't map or validate.
- **TypeScript generation.** `php artisan roma:typescript` emits interfaces for your
  requests and responses — one source of truth for your frontend (great with Inertia).
- **JSON Schema.** `ClassSchema::properties(SearchInput::class)` builds Laravel `JsonSchema`
  types from a class at runtime, for the `schema()` method of an MCP or AI SDK tool.
- **Laravel Precognition.** Works out of the box.
- **AI-ready.** Ships [Laravel Boost](https://github.com/laravel/boost) guidelines, so AI
  coding agents know when and how to reach for Roma.

See the **[full documentation](https://yanelli.dev/docs/roma)** for everything: request
objects, sources, validation & guards, headers & metadata, nested objects, responses, the HTTP
client, config objects, Eloquent casts, TypeScript generation, JSON Schema, and
Precognition.

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see the [License File](LICENSE.md) for more information.
