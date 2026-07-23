# Roma 🍅

[![Tests](https://github.com/byanelli/roma/actions/workflows/run-tests.yml/badge.svg)](https://github.com/byanelli/roma/actions/workflows/run-tests.yml)
[![PHPStan](https://github.com/byanelli/roma/actions/workflows/phpstan.yml/badge.svg)](https://github.com/byanelli/roma/actions/workflows/phpstan.yml)
[![Pint](https://github.com/byanelli/roma/actions/workflows/pint.yml/badge.svg)](https://github.com/byanelli/roma/actions/workflows/pint.yml)
[![Coverage](https://raw.githubusercontent.com/byanelli/roma/badges/coverage.svg)](https://github.com/byanelli/roma/actions/workflows/coverage.yml)

Roma is a **Request/Response Object MApper** for Laravel. It maps the _entire_
`Illuminate\Http\Request` — body, query string, headers, route parameters, cookies, files,
and convenience methods like `$request->ajax()` — into a fully type-safe, validated plain
PHP object, and maps response objects back to JSON. It is a type-safe `FormRequest` and DTO
in one, so your controller never has to touch the underlying Laravel request.

📚 **Full documentation: [yanelli.dev/docs/roma](https://yanelli.dev/docs/roma)**

## Installation

```bash
composer require byanelli/roma
```

## At a glance

Define a request as a typed class, mark it `#[Request]`, and type-hint it in your
controller. Roma maps and validates it before your action runs:

```php
use BYanelli\Roma\Request\Attributes\Rule;
use BYanelli\Roma\Request\ContextualBinding\Request;

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

## Highlights

- **Map from anywhere in the request.** Bind properties to the body, query, headers, route
  parameters, or cookies with `#[Body]`, `#[Query]`, `#[Header]`, `#[RouteParameter]`,
  `#[Cookie]`; the default is the merged input bag.
- **Typed and validated.** Automatic coercion (strings → `int` / `float` / `bool` / enum /
  date / `UploadedFile`), validation via `#[Rule]`, nested objects, and `#[Guard]`
  authorization methods.
- **Header value objects.** Type a property as `Authorization` to get the scheme +
  credentials (with `->basic()` / `->isBearer()`), or use `#[ContentType]` and the `Method`
  / `Scheme` / `ContentType` metadata enums.
- **Response objects.** Return a typed `Response` object and Roma serializes it to JSON,
  with properties liftable to the status code or headers.
- **TypeScript generation.** `php artisan roma:typescript` emits an interface for every
  request and response — one source of truth for your frontend (great with Inertia).
- **Laravel Precognition.** Works out of the box.
- **AI-ready.** Ships [Laravel Boost](https://github.com/laravel/boost) guidelines, so AI
  coding agents know when and how to reach for Roma.

See the **[full documentation](https://yanelli.dev/docs/roma)** for everything: request
objects, sources, validation & guards, headers & metadata, nested objects, responses,
TypeScript generation, and Precognition.

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see the [License File](LICENSE.md) for more information.
