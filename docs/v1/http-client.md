---
extends: _layouts.docs.roma
section: content
title: HTTP Client
package: roma
version: v1
weight: 1
group: HTTP Client
---

# HTTP client

Roma's HTTP client sends a request object through Laravel's HTTP client and maps the response
onto a response class. The request class declares the method, the path and where each
property goes; the response class is mapped and validated the way a request object is, from
the JSON body, the headers and the status. Which class a response maps onto depends on its
status, and error responses can map onto exception classes, which are thrown.

```php
use BYanelli\Roma\Attributes\Guard;
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Client\Attributes\Get;
use BYanelli\Roma\Http\Client\Contracts\TypedFactory;
use Illuminate\Http\Client\ConnectionException;

#[Get('/scrape')]
final readonly class ScrapeRequest
{
    public function __construct(
        #[Query]
        public string $url,
        #[Query('render_js')]
        public bool $renderJs = false,   // render_js=true / false
    ) {}
}

final readonly class ScrapeResponse
{
    public function __construct(
        #[Key('result.content')]
        public string $content,
        #[Key('result.success')]
        public bool $success,
    ) {}

    #[Guard]
    public function succeeded(): void
    {
        $this->success || throw new ScrapeFailed('The scrape was unsuccessful.');
    }
}

final readonly class Scrapfly
{
    public function __construct(private TypedFactory $http, private ScrapflyConfig $config) {}

    public function scrape(string $url, bool $renderJs = false): ScrapeResponse
    {
        return $this->http
            ->baseUrl('https://api.scrapfly.io')
            ->withQueryParameters(['key' => $this->config->apiKey])
            ->timeout($this->config->timeout)
            ->retry(3, 0, fn ($e) => $e instanceof ConnectionException)
            ->sendRequest(new ScrapeRequest($url, $renderJs), [ScrapeResponse::class]);
    }
}
```

## Sending requests

Inject `BYanelli\Roma\Http\Client\Contracts\TypedFactory`, or use the `RomaHttp` facade. It
wraps the container's `Illuminate\Http\Client\Factory`, the same instance the `Http` facade
uses, so `Http::fake()`, `Http::preventStrayRequests()`, global middleware, events and
Telescope apply to its requests.

All of Laravel's methods are available. The configuring methods, such as `baseUrl()`,
`withToken()`, `timeout()` and `retry()`, return a `TypedPendingRequest`, which wraps
Laravel's `PendingRequest` and adds two methods:

- `sendRequest(object $request, array $responses = [], ?string $url = null)` sends a request
  object and returns the mapped response. `$responses` lists response classes for this call,
  and `$url` replaces the URL from the request class, such as a callback URL a caller gives.
- `withResponses(string ...$classes)` adds response classes for all calls made with the
  pending request, such as an SDK's fallback exception.

`TypedFactory` has both methods too. Other calls, such as `get()` or `pool()`, go to the
pending request, and `toPendingRequest()` returns it.

There is no base class for an SDK. An SDK with several endpoints keeps its shared setup in a
private method that returns a configured `TypedPendingRequest`:

```php
private function api(): TypedPendingRequest
{
    return $this->http
        ->baseUrl('https://www.googleapis.com/youtube/v3')
        ->withQueryParameters(['key' => $this->config->apiKey])
        ->withResponses(YouTubeException::class);
}
```

Each `sendRequest()` call works on a copy of the pending request, so a request's headers,
encoding and path parameters don't carry over to the next call made with it.

PHPStan infers the return type from the list passed to `sendRequest()`:
`sendRequest($request, [Video::class, NotFound::class])` returns `Video|NotFound`. Classes
from `#[Responses]` and `withResponses()` aren't part of the inferred type, so pass the
classes a caller uses in the call.

## Request classes

A request class has a method attribute with an optional path: `#[Get('/videos/{id}')]`,
`#[Head]`, `#[Post]`, `#[Put]`, `#[Patch]` or `#[Delete]`, from
`BYanelli\Roma\Http\Client\Attributes`. The path is appended to the base URL.

Each public property needs a destination, and a property without one throws when the
request is first sent:

| Declaration                               | Sent as                                                  |
| ----------------------------------------- | -------------------------------------------------------- |
| `#[Body] public string $title`            | `{"title": ...}` in the body                             |
| `#[Body('meta.color')] public Color $c`   | `{"meta": {"color": "red"}}`                             |
| `#[Query('per_page')] public int $n`      | `?per_page=10`                                           |
| `#[Query] public Filter $filter`          | `?filter[status]=live&filter[min]=1`                     |
| `#[Header('X-Request-Id')] public string` | an `X-Request-Id` header                                 |
| `#[Path('videoId')] public int $id`       | the `{videoId}` parameter of the path                    |
| `public string $id`, path has `{id}`      | the `{id}` parameter, by the property's name             |
| `#[Url] public ?string $next`             | the whole URL, such as a next-page link                  |

- **Values** are serialized with `Profile::clientRequest()`: backed enums as their value,
  dates as ISO 8601 unless the property has `#[DateFormat]`, and booleans in the query string as
  `true` and `false`. A dot in a body or query key nests; a header name keeps its dots.
- **Omitted values.** An unset `#[Optional]` property and a `Missing` value are not sent. A
  null header is not sent, and the query string leaves out null values, as Laravel does.
- **Path parameters** are filled with `PendingRequest::withUrlParameters()`, which escapes
  them, so `a b/c` becomes `a%20b%2Fc`. A path parameter without a property, a `#[Path]`
  property whose name isn't in the path, and a missing value when the request is sent all
  throw.
- **The URL** is the `url` argument of `sendRequest()`, else a non-null `#[Url]` property,
  else the class's path. A URL's own query string, such as a next-page link's, is merged with
  the query parameters, since Guzzle would otherwise replace it with them.
- **Encoding** is JSON. `#[AsForm]` sends the body form-encoded and `#[AsMultipart]` sends it
  as multipart. A property typed as an uploaded file, `Illuminate\Http\UploadedFile` or any
  `Symfony\Component\HttpFoundation\File\File`, must have `#[Body]`, and makes the request
  multipart; nested arrays become bracketed field names (`tags[0]`).
- **No body for GET and HEAD.** A `#[Body]` property on a GET or HEAD request throws.
- **Secrets are sent.** A `#[\SensitiveParameter]` property is sent, since the server is
  where it is meant to go, and it is redacted from Roma's exception messages.
- **Computed values.** A get-only hooked property with a destination is sent like any other.

## Response classes

A response class is mapped like a request object, with `#[Rule]`, nested objects,
`#[Guard]` and the other attributes. Properties read from the JSON body by default:

| Declaration                                 | Read from                                  |
| ------------------------------------------- | ------------------------------------------ |
| `public string $id`                         | the body's `id`                            |
| `#[Key('snippet.title')] public string $t`  | `{"snippet": {"title": ...}}`              |
| `#[Header('X-RateLimit-Remaining')] int $n` | a response header, compared without case   |
| `#[Status] public int $status`              | the status code                            |

A body that isn't JSON has no keys, so a class with required properties fails to map. For an
XML body or a `sink()` download, list Laravel's `Illuminate\Http\Client\Response::class` as a
response class; `sendRequest()` returns the response itself for a 2xx status.

Unknown keys are ignored. Use `#[Guard]` for a 2xx response whose body reports an error,
such as Scrapfly's `"success": false`.

### Injected parameters

A constructor parameter typed as one of these receives a value instead of being mapped. It is
left out of validation:

| Type                                                   | Value                                       |
| ------------------------------------------------------ | ------------------------------------------- |
| `Illuminate\Http\Client\Response`                      | the response                                |
| `Throwable`, `Exception` or Laravel's `RequestException` | the exception for the response, for `previous` |

The exception is Laravel's `RequestException` for a status of 400 and up, the one Laravel
threw. Below 400 Laravel throws nothing, so it is a new `RequestException`, or Roma's
`UnmatchedResponseException` when the class matched as the fallback.

## Matching a response to a class

A class-level `#[Status]` gives the statuses a response class goes with: a code (`404`), a
range (`'4xx'`) or `'default'`. A class can have several. A class without one goes with
`2xx`.

```php
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Client\Attributes\Get;
use BYanelli\Roma\Http\Client\Attributes\Responses;

#[Status(404)]
final readonly class NotFound
{
    public function __construct(
        #[Status]
        public int $status,
    ) {}
}

#[Get('/videos/{id}')]
#[Responses(Video::class, NotFound::class, SubscriberOnlyException::class)]
final readonly class GetVideo
{
    public function __construct(public string $id) {}   // fills {id}
}
```

An exact code wins over a range, and a range over `'default'`. Response classes are declared
in four places, most specific first:

1. the `sendRequest()` argument;
2. `#[Responses]` on the request class;
3. `#[Responses]` on its parent classes, nearest first;
4. `withResponses()`.

The lists are merged by status, and a status declared in two of them goes with the class from
the more specific one. Two classes with the same status in one list throw before the request
is sent.

### Returned or thrown

A matched class that implements `Throwable` is thrown, and any other matched class is
returned. A status the caller branches on, such as a 404 that means "absent", is a plain
class in the returned union; errors are exception classes:

```php
public function video(string $id): ?Video
{
    $response = $this->api()->sendRequest(new GetVideo($id), [Video::class, NotFound::class]);

    return $response instanceof NotFound ? null : $response;
}
```

## Errors

`sendRequest()` calls `throw()` on the pending request, so Laravel throws its
`RequestException` for a status of 400 and up in its usual call stack, and its retry loop
retries according to `retry()`. A `retry()` callback receives Laravel's exception. A callback
given to `throw()` is still called. Roma then catches the exception and matches the status:

- **A matched exception class** is constructed and thrown, with Laravel's exception available
  to inject as its `previous`.
- **A matched plain class** is returned, and Laravel's exception is discarded.
- **An unmatched status of 400 and up** rethrows Laravel's exception unchanged, so code and
  tools that look for a `RequestException` see what they would without Roma.
- **An unmatched status below 400** has no Laravel exception, so Roma throws
  `UnmatchedResponseException`, a `RequestException` whose message lists the declared
  response classes.
- **A connection failure** has no response, and Laravel's `ConnectionException` propagates
  unchanged.

A matched class that fails to map throws `MalformedResponseException`, a
`MappingValidationException` with the status, the errors, the start of the body and
Laravel's exception as `previous`:

```
Roma could not map the HTTP 200 response onto Video: body.snippet.title: The body.snippet.title field is required.
Body: {"id":"v1"}
```

Roma's messages never include the URL or the query string, since some APIs, such as
Scrapfly, put the key in the query string. The values of `#[\SensitiveParameter]` properties
of the request object and the response class are replaced with `[redacted]`. Laravel's own
`RequestException` and `ConnectionException` are passed on unchanged, so their messages are
Laravel's: a `ConnectionException` from Guzzle includes the URL.

### The fallback

A class with `#[Status('default')]` goes with any status no other class matches, including
an unmatched 2xx. An SDK puts its general exception there with `withResponses()`:

```php
use BYanelli\Roma\Attributes\Key;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

#[Status('default')]
class YouTubeException extends RequestException
{
    public function __construct(
        Response $response,
        #[Key('error.message')]
        public readonly ?string $reason = null,
    ) {
        parent::__construct($response);
    }
}
```

A fallback that fails to map, because an unexpected error body lacks its required fields,
rethrows Laravel's original exception, or `UnmatchedResponseException` below 400, instead of a
mapping error. Give a fallback's properties defaults so that it maps from any body.

### Exception classes

- **Extend `RequestException`** so that `catch (RequestException)` blocks, `report()` filters
  and error reporters treat the exception as Laravel's. Pass the injected response to its
  constructor.
- **Constructor only.** An exception class is built through its constructor, and public
  properties declared outside it are not mapped, so Laravel's `RequestException::$response`
  keeps the value its constructor sets.
- **Not readonly.** PHP doesn't allow a readonly exception class, but its properties can be.
- **`$message`** can't be redeclared with a type, since `Exception::$message` has none. Map
  the reason to another property and pass it to the parent's constructor.

## Testing

`Http::fake()` works as usual. Recorded JSON fixtures faked by URL check the response classes
against the real API's keys, which a typed fake can't do, since a typed fake is serialized
with the definition that maps it back.

`RomaHttp::fake()` also takes request classes as keys. A value can be a response object,
which is serialized as the API would send it, with backed enums as their value and sensitive
values included; a closure typed with the request class, which receives the request object;
or anything `Http::fake()` accepts, such as `Http::response()`, a status or a sequence. Keys
that aren't classes are URL patterns, as for `Http::fake()`:

```php
use BYanelli\Roma\Facades\RomaHttp;

RomaHttp::fake([
    ScrapeRequest::class => new ScrapeResponse('<html/>', success: true),
    GetVideo::class      => fn (GetVideo $request) => $request->id === 'gone' ? new NotFound(404) : new Video($request->id),
    'api.example.com/*'  => Http::response(['ok' => true]),
]);
```

The status is the object's `#[Status]` property, else its class-level `#[Status]` (the first
code of a range), else 200. A class whose `#[Status]` is `'default'` needs one:
`RomaHttp::response($object, 500)` serializes an object with a given status and headers,
and also works inside `Http::fake()` and `Http::sequence()`. Only the keys the class maps
are written, so the public properties of an exception class's parents are left out.

Each request sent with `sendRequest()` has its request object in a request attribute, so
assertions can match on it, whether the request was faked by class or by URL:

```php
RomaHttp::assertSent(ScrapeRequest::class);
RomaHttp::assertSent(fn (ScrapeRequest $request) => $request->renderJs && $request->url === $url);
RomaHttp::assertNotSent(GetVideo::class);

$requests = RomaHttp::sent(ScrapeRequest::class);   // list<ScrapeRequest>
```

A callback also receives Laravel's request and response. A callback typed with Laravel's
`Illuminate\Http\Client\Request` goes to `Http::assertSent()`, and values set on the pending
request, such as `withQueryParameters()`, are only visible that way.

## Not supported yet

- Async requests and pools. `sendRequest()` throws for a pending request set to `async()`.
- PHPStan types for classes from `#[Responses]` and `withResponses()`, and for which classes
  are thrown.
