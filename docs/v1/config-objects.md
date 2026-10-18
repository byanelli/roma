---
extends: _layouts.docs.roma
section: content
title: Config Objects
package: roma
version: v1
weight: 1
group: Config
---

# Config objects

A config object is a class whose properties Roma reads from Laravel's config repository,
with the same coercion and `#[Rule]` validation as a request. A missing API key or a timeout
of `"abc"` is reported when the object is first used, with the config key in the message,
instead of surfacing later as a 401 or a zero. `roma:config:validate` reports it
[before a deploy](#validating-before-a-deploy).

```php
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Config\ConfigKey;
use BYanelli\Roma\Config\ConfigObject;

#[ConfigObject('services.scrapfly')]
readonly class ScrapflyConfig
{
    public function __construct(
        #[Key('key'), \SensitiveParameter] public string $apiKey,  // services.scrapfly.key
        public bool $renderJs = false,                             // services.scrapfly.render_js
        #[ConfigKey('articles.scrapfly_timeout'), Rule('min:1')]
        public int $timeout = 180,                                 // articles.scrapfly_timeout
    ) {}
}
```

Type-hint the class wherever you need it:

```php
class ScrapflyClient
{
    public function __construct(private ScrapflyConfig $config) {}
}
```

## Keys

`#[ConfigObject('services.scrapfly')]` sets the prefix. A property's key is relative to it:

| Declaration                                  | Config key                     |
| -------------------------------------------- | ------------------------------ |
| `public string $apiKey`                      | `services.scrapfly.api_key`    |
| `#[Key('key')] public string $apiKey`        | `services.scrapfly.key`        |
| `#[Key('limits.per_minute')] public int $x`  | `services.scrapfly.limits.per_minute` |
| `#[ConfigKey('articles.timeout')] public int $x` | `articles.timeout`         |

- A property without a key attribute reads the snake_case form of its name. This applies to
  config objects only; requests and arrays use the property name as it is.
- A dot in `#[Key]` separates keys, as in `config()`.
- `#[ConfigKey]` is an absolute key. Only a top-level property may have one.
- A nested object or an array of them reads from under its property's key, and its own keys
  follow the same rules: `public Credentials $oxylabs` reads
  `services.scrapfly.oxylabs.user` and `services.scrapfly.oxylabs.password`.

Roma reads the config repository only, never `env()`, so a cached config (`config:cache`)
needs no special handling.

## Values

Config files read most values from the environment, so they are strings. Roma coerces them
to the property's type as it does query parameters, so remove the casts from your config
files and let Roma validate the raw value:

```php
// config/articles.php
return [
    'scrapfly_timeout' => env('SCRAPFLY_TIMEOUT', 180),   // not (int) env(...)
];
```

`(int) env('SCRAPFLY_TIMEOUT')` turns `"abc"` into `0`, which passes an `int` property. Without
the cast, Roma reports "The articles.scrapfly_timeout field must be an integer." A boolean
accepts `true`/`false`, `1`/`0`, `yes`/`no` and `on`/`off`, in any case, so
`(bool) env(...)` can go as well. An enum property lists the valid values in its message:
"The services.scraper.provider field must be one of: scrapfly, zyte."

### Missing and blank values

A key that is missing, `null`, or a blank string (empty or only whitespace) is missing. An
unset variable copied from `.env.example` is `KEY=`, an empty string, so it counts as
missing too:

| Property                             | Value missing, `null` or blank |
| ------------------------------------ | ------------------------------ |
| `public int $timeout = 180`          | `180`                          |
| `public ?string $region`             | `null`                         |
| `public string $apiKey`              | "The services.scrapfly.key field is required." |

A nested object whose values are all missing is missing as well, so a nullable one is
`null`.

## Lists, durations and sizes

Three types cover values that are often set in `.env`. They work in requests and plain
arrays as well.

```php
use BYanelli\Roma\Attributes\Delimited;
use BYanelli\Roma\Attributes\Unit;
use BYanelli\Roma\Values\ByteSize;
use Carbon\CarbonInterval;

#[ConfigObject('services.uploads')]
readonly class UploadsConfig
{
    /**
     * @param  list<string>  $mimeTypes
     */
    public function __construct(
        #[Delimited] public array $mimeTypes = ['image/png'],      // "image/png, image/webp"
        public CarbonInterval $retention = new CarbonInterval(days: 30), // "90 days", "PT12H"
        #[Unit('seconds')] public CarbonInterval $timeout = new CarbonInterval(seconds: 30), // "90" or "2 minutes"
        public ByteSize $maxSize = new ByteSize(10_000_000),       // "25MB", "512KiB" or 1048576
    ) {}
}
```

- **`#[Delimited]`** on an array property splits a string on `,` (or the delimiter you
  pass), trims the items and drops empty ones. An array value is used as it is. The items are
  coerced and validated as the array's element type.
- **`CarbonInterval`** (or `DateInterval`) accepts what `CarbonInterval::make()` does:
  `"90 seconds"`, `"1 hour 30 minutes"`, `"2h 5m"` or an ISO 8601 duration such as `"PT90S"`.
  With `#[Unit('seconds')]`, a number or numeric string is a count of that unit; any unit
  Carbon accepts works, such as `milliseconds`, `minutes` or `days`. An invalid value fails
  with "The services.uploads.timeout field must be a number of seconds or a duration such as
  "90 seconds"."
- **`ByteSize`** accepts a number of bytes, as an int or a string, or a number with a unit:
  `KB`, `MB`, `GB` and `TB` are powers of 1000, and `KiB`, `MiB`, `GiB` and `TiB` powers of
  1024. Units are case-insensitive, and a fraction is allowed when the result is a whole
  number of bytes (`"1.5GB"`). `"10M"` is rejected, since it could mean either. Read the size
  from `$size->bytes`. It serializes as its number of bytes, as a string.

In TypeScript and JSON Schema, an interval and a `ByteSize` are strings.

## Resolving

A class with `#[ConfigObject]` resolves from the container by type. The container returns a
[lazy proxy](https://www.php.net/manual/en/language.oop5.lazy-objects.php): the object is
mapped and validated when a property is first read, and invalid config throws then. A class
that injects the config of a service it doesn't use, such as the scraper a request never
calls, resolves without that service's keys.

```php
$config = app(ScrapflyConfig::class);  // doesn't read the config
$config->apiKey;                       // maps it, or throws ConfigValidationException
```

- The config is read on first access, so a test can change it with `config([...])` after
  resolving the object.
- If the first access throws, the object stays unmapped, and the next access maps it again
  and throws again if the config is still invalid.
- PHPUnit's `assertEquals()` and Pest's `toEqual()` see no properties on an object that
  hasn't been read, so the comparison fails. Read a property first, or use
  [`Mapper::fromConfig()`](#mapping-by-hand).

Since an error appears only when the config is used, run
[`roma:config:validate`](#validating-before-a-deploy) in CI and in your deploy script to
catch it before then.

Laravel's container doesn't share the object, so each resolution maps the config again. Add
Laravel's `#[Singleton]` (or `#[Scoped]`) to share it:

```php
use Illuminate\Container\Attributes\Singleton;

#[ConfigObject('services.scrapfly'), Singleton]
readonly class ScrapflyConfig { /* ... */ }
```

The container then shares the proxy, so the config is mapped once, on first access. An
explicit binding in a service provider takes precedence.

### One class, several prefixes

`#[ConfigObject]` on a constructor parameter sets the prefix for that parameter, and
overrides a class-level prefix. The parameter is a lazy proxy as well. One class can then be
used for several services:

```php
readonly class ProxyCredentials
{
    public function __construct(
        public string $user,
        #[\SensitiveParameter] public string $password,
    ) {}
}

class OxylabsProxy
{
    public function __construct(
        #[ConfigObject('services.oxylabs.residential')] private ProxyCredentials $credentials,
    ) {}
}
```

### Mapping by hand

```php
use BYanelli\Roma\Facades\Mapper;

$config = Mapper::fromConfig(ScrapflyConfig::class);
$credentials = Mapper::fromConfig(ProxyCredentials::class, 'services.data_impulse.residential');
```

The second argument overrides the class's prefix, and is required for a class without one.
`fromConfig()` maps and validates immediately and returns the object itself, not a proxy.

## Errors

Invalid config throws `BYanelli\Roma\Config\ConfigValidationException`, a subclass of
`MappingValidationException`. Its error keys are full config keys, and its message lists the
class and all the errors, since it is usually read in a log:

```
Roma config object App\Apis\Scrapfly\ScrapflyConfig is invalid:
  services.scrapfly.key: The services.scrapfly.key field is required.
  articles.scrapfly_timeout: The articles.scrapfly_timeout field must be an integer.
```

`$e->errors()` returns the same messages keyed by config key.

### Secrets

PHP keeps the arguments of a `#[\SensitiveParameter]` parameter out of stack traces. Roma also
replaces its value with `[redacted]` in the messages of a `ConfigValidationException`, so a
rule message that includes the value, such as a custom rule's, doesn't put a secret in the
log. The exception doesn't chain the original one, whose messages aren't redacted. The
[serializer](/docs/roma/v1/responses) leaves the property out of its output, so a config object
passed to `Serializer::toArray()` doesn't include the secret.

## Validating before a deploy

```
$ php artisan roma:config:validate

  App\Apis\Scrapfly\ScrapflyConfig ........................ FAIL
    services.scrapfly.key  The services.scrapfly.key field is required.
  App\Apis\Tts\GeminiConfig ............................... OK

   ERROR  1 of 2 config objects failed validation.
```

The command maps each class with a class-level `#[ConfigObject]` in the
[`roma.discover`](/docs/roma/v1/typescript#auto-detection) directories and exits with 1 if any fails. It
maps them immediately, unlike the container, so it reports config that the application would
reject only when a request uses it. Run it in CI and in your deploy script after
`config:cache`, so it checks the config the application will use.

- `php artisan optimize` runs it as well, but `optimize` reports a failed task and still
  succeeds, so it won't stop a deploy. Call `roma:config:validate` yourself for that.
- A class used only with a parameter-level prefix has no prefix of its own, so the command
  can't validate it. It is still validated on first access.
- `php artisan roma:cache`, which `optimize` runs, saves the list of classes to
  `bootstrap/cache/roma.php`. While that file exists it is the only source, so run it again
  after adding a config object.
