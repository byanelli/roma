<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Facades\RomaHttp;
use BYanelli\Roma\Http\Attributes\Path;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Client\Attributes\Get;
use BYanelli\Roma\Http\Client\Attributes\Responses;
use BYanelli\Roma\Http\Client\Exceptions\MalformedResponseException;
use BYanelli\Roma\Http\Client\Exceptions\UnmatchedResponseException;
use BYanelli\Roma\Http\Client\TypedPendingRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class MatchVideo
{
    public function __construct(public string $id, #[Key('snippet.title')] public string $title) {}
}

#[Status(404)]
final readonly class MatchNotFound
{
    public function __construct(#[Status] public int $status) {}
}

#[Status(404)]
final readonly class MatchMissing {}

#[Status(404), Status(410)]
final readonly class MatchGone {}

#[Status('4xx')]
final readonly class MatchClientError
{
    public function __construct(#[Key('error.message')] public string $message) {}
}

#[Status(403)]
class MatchSubscriberOnlyException extends RequestException
{
    public function __construct(Response $response, #[Key('error.message')] public readonly string $reason)
    {
        parent::__construct($response);
    }
}

#[Status('default')]
class MatchApiException extends RuntimeException
{
    public function __construct(
        Throwable $previous,
        #[Key('error.message')] public readonly ?string $reason = null,
    ) {
        parent::__construct('The API failed: '.($reason ?? 'unknown'), previous: $previous);
    }
}

#[Status('default')]
class MatchStrictApiException extends RuntimeException
{
    public function __construct(#[Key('error.message')] public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

#[Status('5xx')]
final readonly class MatchServerError {}

#[Status(200)]
final readonly class MatchOk {}

final readonly class MatchInjected
{
    public function __construct(
        public Response $response,
        public Throwable $throwable,
        public Exception $exception,
        public RequestException $requestException,
        public ?string $id = null,
    ) {}
}

final readonly class MatchToken
{
    public function __construct(#[SensitiveParameter, Rule('max:3')] public string $token) {}
}

#[Get('/videos/{id}')]
#[Responses(MatchVideo::class, MatchNotFound::class, MatchSubscriberOnlyException::class)]
final readonly class MatchGetVideo
{
    public function __construct(public string $id = 'v1', #[Query, SensitiveParameter] public string $key = 'sk-secret') {}
}

#[Get('/videos')]
final readonly class MatchListVideos {}

#[Get('/search')]
final readonly class MatchSearch
{
    public function __construct(#[Query, SensitiveParameter] public string $key = 'sk-secret') {}
}

#[Responses(MatchMissing::class, MatchServerError::class)]
abstract class MatchBaseRequest {}

#[Get('/items/{id}')]
#[Responses(MatchNotFound::class)]
class MatchChildRequest extends MatchBaseRequest
{
    public function __construct(#[Path] public string $id = 'i1') {}
}

function matchApi(): TypedPendingRequest
{
    return RomaHttp::baseUrl('https://api.test');
}

beforeEach(fn () => Http::preventStrayRequests());

it('returns the class for a 2xx response and a plain class for an error status', function () {
    Http::fake([
        'api.test/videos/v1*' => Http::response(['id' => 'v1', 'snippet' => ['title' => 'Hi']]),
        'api.test/videos/v2*' => Http::response(['error' => 'nope'], 404),
    ]);

    expect(matchApi()->sendRequest(new MatchGetVideo))->toEqual(new MatchVideo('v1', 'Hi'))
        ->and(matchApi()->sendRequest(new MatchGetVideo('v2')))->toEqual(new MatchNotFound(404));
});

it('throws a matched exception class with Laravel\'s exception and the response injected', function () {
    Http::fake(['api.test/*' => Http::response(['error' => ['message' => 'Members only']], 403)]);
    $laravel = null;

    try {
        matchApi()->throw(function (Response $response, RequestException $e) use (&$laravel) {
            $laravel = $e;
        })->sendRequest(new MatchGetVideo);
        $this->fail('Expected an exception.');
    } catch (RequestException $e) {
        expect($e)->toBeInstanceOf(MatchSubscriberOnlyException::class)
            ->and($e->reason)->toBe('Members only')
            ->and($e->response->status())->toBe(403)
            ->and($e->getCode())->toBe(403)
            ->and($laravel)->toBeInstanceOf(RequestException::class)
            ->and($laravel)->not->toBe($e);
    }
});

it('prefers an exact code to a range and a range to default', function () {
    Http::fake([
        'api.test/videos/a*' => Http::response([], 404),
        'api.test/videos/b*' => Http::response(['error' => ['message' => 'Bad']], 422),
        'api.test/videos/c*' => Http::response(['error' => ['message' => 'Down']], 503),
    ]);
    $classes = [MatchApiException::class, MatchClientError::class, MatchMissing::class];

    expect(matchApi()->sendRequest(new MatchListVideos, $classes, url: '/videos/a'))->toEqual(new MatchMissing)
        ->and(matchApi()->sendRequest(new MatchListVideos, $classes, url: '/videos/b'))->toEqual(new MatchClientError('Bad'))
        ->and(fn () => matchApi()->sendRequest(new MatchListVideos, $classes, url: '/videos/c'))
        ->toThrow(MatchApiException::class, 'The API failed: Down');
});

it('matches a class to each of its statuses', function () {
    Http::fake(['api.test/*' => Http::response([], 410)]);

    expect(matchApi()->sendRequest(new MatchListVideos, [MatchGone::class]))->toEqual(new MatchGone);
});

it('rejects two classes with the same status in one list', function (array $classes, string $message) {
    Http::fake(['api.test/*' => Http::response([])]);

    expect(fn () => matchApi()->sendRequest(new MatchListVideos, $classes))->toThrow(RuntimeException::class, $message);
})->with([
    'a code' => [[MatchNotFound::class, MatchMissing::class], 'Roma response classes MatchNotFound and MatchMissing both go with status 404 in the sendRequest() call.'],
    'a code among several' => [[MatchMissing::class, MatchGone::class], 'MatchMissing and MatchGone both go with status 404'],
    'the 2xx default' => [[MatchVideo::class, MatchInjected::class], 'MatchVideo and MatchInjected both go with status 2xx'],
    'default' => [[MatchApiException::class, MatchStrictApiException::class], 'both go with status default'],
]);

it('rejects two classes with the same status in withResponses()', function () {
    Http::fake(['api.test/*' => Http::response([])]);

    expect(fn () => matchApi()->withResponses(MatchNotFound::class)->withResponses(MatchMissing::class)->sendRequest(new MatchListVideos))
        ->toThrow(RuntimeException::class, 'both go with status 404 in withResponses()');
});

it('rejects a response class that does not exist', function () {
    expect(fn () => matchApi()->sendRequest(new MatchListVideos, ['MatchNoSuchClass']))
        ->toThrow(RuntimeException::class, 'Roma response class MatchNoSuchClass does not exist.');
});

it('merges the lists by status, the more specific first', function () {
    Http::fake(['api.test/*' => Http::sequence()->pushStatus(404)->pushStatus(404)->pushStatus(500)->pushStatus(404)]);
    $request = new MatchChildRequest;

    // #[Responses] on the class wins over its parent's and over withResponses().
    expect(matchApi()->withResponses(MatchGone::class)->sendRequest($request))->toEqual(new MatchNotFound(404))
        // The sendRequest() list wins over #[Responses].
        ->and(matchApi()->sendRequest($request, [MatchMissing::class]))->toEqual(new MatchMissing)
        // A status only the parent declares comes from the parent.
        ->and(matchApi()->withResponses(MatchApiException::class)->sendRequest($request))->toEqual(new MatchServerError)
        // withResponses() fills statuses no other list declares, and never replaces one.
        ->and(matchApi()->withResponses(MatchMissing::class)->sendRequest($request))->toEqual(new MatchNotFound(404));
});

it('throws the fallback from withResponses() for a status no other class matches', function () {
    Http::fake(['api.test/*' => Http::response(['error' => ['message' => 'Quota']], 429)]);

    try {
        matchApi()->withResponses(MatchApiException::class)->sendRequest(new MatchGetVideo);
        $this->fail('Expected an exception.');
    } catch (MatchApiException $e) {
        expect($e->reason)->toBe('Quota')
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class)
            ->and($e->getPrevious()?->getCode())->toBe(429);
    }
});

it('rethrows Laravel\'s exception unchanged for an unmatched status of 400 and up', function () {
    Http::fake(['api.test/*' => Http::response('Server on fire', 500)]);
    $laravel = null;

    try {
        matchApi()->throw(function (Response $response, RequestException $e) use (&$laravel) {
            $laravel = $e;
        })->sendRequest(new MatchGetVideo);
        $this->fail('Expected an exception.');
    } catch (Throwable $e) {
        expect($e)->toBe($laravel)
            ->and($e::class)->toBe(RequestException::class)
            ->and($e->getMessage())->toBe("HTTP request returned status code 500:\nServer on fire\n");
    }
});

it('rethrows Laravel\'s exception unchanged when the fallback fails to map', function () {
    Http::fake(['api.test/*' => Http::response(['unexpected' => true], 502)]);
    $laravel = null;

    try {
        matchApi()->withResponses(MatchStrictApiException::class)->throw(function (Response $response, RequestException $e) use (&$laravel) {
            $laravel = $e;
        })->sendRequest(new MatchGetVideo);
        $this->fail('Expected an exception.');
    } catch (Throwable $e) {
        expect($laravel)->toBeInstanceOf(RequestException::class)
            ->and($e)->toBe($laravel);
    }
});

it('throws UnmatchedResponseException for an unmatched 2xx status, without the URL or sensitive values', function () {
    Http::fake(['api.test/*' => Http::response('created with sk-secret', 201)]);

    try {
        matchApi()->sendRequest(new MatchSearch, [MatchOk::class]);
        $this->fail('Expected an exception.');
    } catch (UnmatchedResponseException $e) {
        expect($e)->toBeInstanceOf(RequestException::class)
            ->and($e->response->status())->toBe(201)
            ->and($e->requestClass)->toBe(MatchSearch::class)
            ->and($e->getMessage())->toBe("Roma has no response class for the HTTP 201 response to MatchSearch. Response classes: 200: MatchOk.\ncreated with [redacted]\n")
            ->and((string) $e)->not->toContain('api.test')
            ->and((string) $e)->not->toContain('sk-secret');
    }
});

it('throws the fallback with UnmatchedResponseException injected for an unmatched 2xx status', function () {
    Http::fake(['api.test/*' => Http::response([], 204)]);

    try {
        matchApi()->withResponses(MatchApiException::class)->sendRequest(new MatchListVideos, [MatchOk::class]);
        $this->fail('Expected an exception.');
    } catch (MatchApiException $e) {
        expect($e->getPrevious())->toBeInstanceOf(UnmatchedResponseException::class);
    }
});

it('throws MalformedResponseException when a matched class fails to map', function () {
    Http::fake(['api.test/*' => Http::response(['id' => 'v1', 'echo' => 'sk-secret'])]);

    try {
        matchApi()->sendRequest(new MatchGetVideo);
        $this->fail('Expected an exception.');
    } catch (MalformedResponseException $e) {
        expect($e->status())->toBe(200)
            ->and($e->className())->toBe(MatchVideo::class)
            ->and($e->errors())->toBe(['body.snippet.title' => ['The body.snippet.title field is required.']])
            ->and($e->getPrevious())->toBeNull()
            ->and($e->getMessage())->toBe(
                'Roma could not map the HTTP 200 response onto MatchVideo: body.snippet.title: The body.snippet.title field is required.'
                ."\nBody: {\"id\":\"v1\",\"echo\":\"[redacted]\"}"
            )
            ->and((string) $e)->not->toContain('api.test')
            ->and((string) $e)->not->toContain('sk-secret');
    }
});

it('chains Laravel\'s exception to MalformedResponseException for an error status', function () {
    Http::fake(['api.test/*' => Http::response(['error' => 'flat'], 422)]);

    try {
        matchApi()->sendRequest(new MatchListVideos, [MatchClientError::class]);
        $this->fail('Expected an exception.');
    } catch (MalformedResponseException $e) {
        expect($e->getPrevious())->toBeInstanceOf(RequestException::class)
            ->and($e->errors())->toHaveKey('body.error.message');
    }
});

it('redacts the response class\'s sensitive values from MalformedResponseException', function () {
    Http::fake(['api.test/*' => Http::response(['token' => 'tok_12345'])]);

    try {
        matchApi()->sendRequest(new MatchListVideos, [MatchToken::class]);
        $this->fail('Expected an exception.');
    } catch (MalformedResponseException $e) {
        expect((string) $e)->not->toContain('tok_12345')
            ->and($e->body())->toBe('{"token":"[redacted]"}');
    }
});

it('injects the response and the exception, and leaves them out of validation', function () {
    Http::fake(['api.test/*' => Http::response(['id' => 'v1'])]);

    $result = matchApi()->sendRequest(new MatchListVideos, [MatchInjected::class]);

    expect($result->response->json())->toBe(['id' => 'v1'])
        ->and($result->throwable)->toBeInstanceOf(RequestException::class)
        ->and($result->exception)->toBe($result->throwable)
        ->and($result->requestException)->toBe($result->throwable)
        ->and($result->id)->toBe('v1');
});

it('retries with retry() and maps the last response', function () {
    Http::fake(['api.test/*' => Http::sequence()->pushStatus(500)->pushStatus(500)->push(['id' => 'v1', 'snippet' => ['title' => 'Hi']])]);

    expect(matchApi()->retry(3, 0)->sendRequest(new MatchGetVideo))->toEqual(new MatchVideo('v1', 'Hi'));

    Http::assertSentCount(3);
});

it('gives retry() callbacks Laravel\'s exception and maps the response it stops on', function () {
    Http::fake(['api.test/*' => Http::sequence()->pushStatus(500)->pushStatus(404)]);
    $seen = [];

    $result = matchApi()->retry(3, 0, function (Throwable $e) use (&$seen) {
        $seen[] = $e::class;

        return $e instanceof RequestException && $e->response->serverError();
    })->sendRequest(new MatchGetVideo);

    expect($result)->toEqual(new MatchNotFound(404))
        ->and($seen)->toBe([RequestException::class, RequestException::class]);

    Http::assertSentCount(2);
});

it('throws the matched class after the last retry', function () {
    Http::fake(['api.test/*' => Http::response(['error' => ['message' => 'Down']], 503)]);

    expect(fn () => matchApi()->retry(2, 0)->withResponses(MatchApiException::class)->sendRequest(new MatchGetVideo))
        ->toThrow(MatchApiException::class, 'The API failed: Down');

    Http::assertSentCount(2);
});

it('lets a connection failure propagate unchanged after retries', function () {
    $failure = new ConnectionException('cURL error 28');
    Http::fake(fn () => throw $failure);

    expect(fn () => matchApi()->retry(2, 0)->sendRequest(new MatchGetVideo))->toThrow(fn (ConnectionException $e) => expect($e)->toBe($failure));
});

it('matches a failed response that Laravel did not throw for', function () {
    Http::fake(['api.test/*' => Http::sequence()->pushStatus(404)->push('Oops', 500)]);
    $api = fn () => matchApi()->throwIf(fn (Response $response) => false);

    expect($api()->sendRequest(new MatchGetVideo))->toEqual(new MatchNotFound(404))
        ->and(fn () => $api()->sendRequest(new MatchGetVideo))->toThrow(RequestException::class, "HTTP request returned status code 500:\nOops\n");
});

it('constructs an exception class through its constructor only', function () {
    Http::fake(['api.test/*' => Http::response(['error' => ['message' => 'No'], 'response' => 'body', 'hasBeenSummarized' => true], 403)]);

    try {
        matchApi()->sendRequest(new MatchGetVideo);
        $this->fail('Expected an exception.');
    } catch (MatchSubscriberOnlyException $e) {
        expect($e->response)->toBeInstanceOf(Response::class)
            ->and($e->hasBeenSummarized)->toBeFalse();
    }
});
