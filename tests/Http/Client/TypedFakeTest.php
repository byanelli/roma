<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Facades\RomaHttp;
use BYanelli\Roma\Http\Attributes\Body;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Client\Attributes\Get;
use BYanelli\Roma\Http\Client\Attributes\Post;
use BYanelli\Roma\Http\Client\Attributes\Responses;
use BYanelli\Roma\Http\Client\TypedFactory;
use BYanelli\Roma\Http\Client\TypedPendingRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;

enum FakeLevel: string
{
    case High = 'high';
}

final readonly class FakeResult
{
    public function __construct(
        public string $content,
        #[Key('final.url')]
        public string $url,
        public FakeLevel $level,
        #[SensitiveParameter]
        public string $token,
        #[Header('X-Cost')]
        public int $cost,
    ) {}
}

#[Status(404)]
final readonly class FakeNotFound
{
    public function __construct(
        #[Status]
        public int $status = 404,
    ) {}
}

#[Status('4xx')]
final readonly class FakeClientError
{
    public function __construct(public string $message) {}
}

#[Status(403)]
class FakeForbiddenException extends RequestException
{
    public function __construct(
        Response $response,
        #[Key('error.message')]
        public readonly string $reason,
    ) {
        parent::__construct($response);
    }
}

#[Status('default')]
final readonly class FakeFallback {}

#[Get('/scrape')]
#[Responses(FakeResult::class, FakeNotFound::class, FakeForbiddenException::class)]
final readonly class FakeScrape
{
    public function __construct(
        #[Query]
        public string $url,
        #[Query]
        public bool $renderJs = false,
        #[Query]
        public string $country = 'us',
    ) {}
}

#[Post('/notes')]
final readonly class FakeCreateNote
{
    public function __construct(
        #[Body]
        public string $text,
    ) {}
}

function fakeResult(): FakeResult
{
    return new FakeResult('<html/>', 'https://archive.is/x', FakeLevel::High, 'tok_1', 30);
}

function fakeApi(): TypedPendingRequest
{
    return RomaHttp::baseUrl('https://api.test');
}

beforeEach(fn () => Http::preventStrayRequests());

it('fakes a response by request class and maps it back to an equal object', function () {
    RomaHttp::fake([FakeScrape::class => fakeResult()]);

    expect(fakeApi()->sendRequest(new FakeScrape('https://a.test')))->toEqual(fakeResult());
});

it('sends the status from the #[Status] property or the class', function () {
    RomaHttp::fake([FakeScrape::class => new FakeNotFound]);

    expect(fakeApi()->sendRequest(new FakeScrape('https://a.test')))->toEqual(new FakeNotFound);
});

it('throws a faked exception class', function () {
    $exception = new FakeForbiddenException(new Response(new PsrResponse(403)), 'Members only');
    RomaHttp::fake([FakeScrape::class => $exception]);

    expect(fn () => fakeApi()->sendRequest(new FakeScrape('https://a.test')))
        ->toThrow(fn (FakeForbiddenException $e) => expect($e->reason)->toBe('Members only')->and($e->response->json())->toBe(['error' => ['message' => 'Members only']]));
});

it('passes the request object to a fake closure typed with its class', function () {
    RomaHttp::fake([FakeScrape::class => fn (FakeScrape $request) => $request->renderJs ? fakeResult() : new FakeNotFound]);

    expect(fakeApi()->sendRequest(new FakeScrape('https://a.test', renderJs: true)))->toEqual(fakeResult())
        ->and(fakeApi()->sendRequest(new FakeScrape('https://a.test')))->toEqual(new FakeNotFound);
});

it('passes Laravel\'s request to a fake closure typed with it', function () {
    RomaHttp::fake([FakeScrape::class => fn (Request $request) => str_contains($request->url(), 'country=us') ? Http::response([], 404) : null]);

    expect(fakeApi()->sendRequest(new FakeScrape('https://a.test')))->toEqual(new FakeNotFound);
});

it('accepts Laravel\'s fake responses, sequences and statuses as values', function () {
    RomaHttp::fake([FakeScrape::class => Http::sequence()->pushStatus(404)->push(['message' => 'Bad'], 422)]);

    expect(fakeApi()->sendRequest(new FakeScrape('https://a.test')))->toEqual(new FakeNotFound)
        ->and(fakeApi()->sendRequest(new FakeScrape('https://a.test'), [FakeClientError::class]))->toEqual(new FakeClientError('Bad'));
});

it('accepts a status as a value', function () {
    RomaHttp::fake([FakeScrape::class => 404]);

    expect(fakeApi()->sendRequest(new FakeScrape('https://a.test')))->toEqual(new FakeNotFound);
});

it('fakes by URL alongside request classes', function () {
    RomaHttp::fake([
        FakeScrape::class => new FakeNotFound,
        'api.test/notes' => new FakeNotFound(410),
    ]);

    expect(fakeApi()->sendRequest(new FakeScrape('https://a.test')))->toEqual(new FakeNotFound)
        ->and(fakeApi()->sendRequest(new FakeCreateNote('hi'), [FakeNotFound::class, FakeFallback::class]))->toEqual(new FakeFallback);
});

it('serializes a response object for Http::fake() and sequences', function () {
    Http::fake(['api.test/*' => Http::sequence()->pushResponse(RomaHttp::response(fakeResult()))->pushResponse(RomaHttp::response(new FakeNotFound(404), headers: ['X-Debug' => '1']))]);

    expect(fakeApi()->sendRequest(new FakeScrape('https://a.test')))->toEqual(fakeResult())
        ->and(fakeApi()->sendRequest(new FakeScrape('https://a.test')))->toEqual(new FakeNotFound);

    expect(Http::recorded()->last()[1]->header('X-Debug'))->toBe('1');
});

it('writes the response as an API would send it', function () {
    $response = new Response(RomaHttp::response(fakeResult())->wait());

    expect($response->status())->toBe(200)
        ->and($response->json())->toBe(['content' => '<html/>', 'final' => ['url' => 'https://archive.is/x'], 'level' => 'high', 'token' => 'tok_1'])
        ->and($response->header('X-Cost'))->toBe('30');
});

it('sends the first code of a class-level range', function () {
    expect(RomaHttp::response(new FakeClientError('Bad'))->wait()->getStatusCode())->toBe(400)
        ->and(RomaHttp::response(new FakeClientError('Bad'), 422)->wait()->getStatusCode())->toBe(422);
});

it('needs a status for a class whose #[Status] is default', function () {
    expect(fn () => RomaHttp::response(new FakeFallback))
        ->toThrow(InvalidArgumentException::class, "Roma cannot tell the status of a fake FakeFallback's response, since its #[Status] is 'default'. Pass a status to RomaHttp::response().")
        ->and(RomaHttp::response(new FakeFallback, 500)->wait()->getStatusCode())->toBe(500);
});

it('still prevents stray requests that no typed fake matches', function () {
    RomaHttp::fake([FakeScrape::class => fakeResult()]);

    expect(fn () => fakeApi()->sendRequest(new FakeCreateNote('hi')))->toThrow(StrayRequestException::class);
});

it('fakes all requests with an empty 200 response without stubs', function () {
    RomaHttp::fake();

    expect(fakeApi()->sendRequest(new FakeCreateNote('hi'), [FakeFallback::class]))->toEqual(new FakeFallback);
});

it('asserts sent requests by class and by a callback typed with the class', function () {
    RomaHttp::fake([FakeScrape::class => fakeResult(), FakeCreateNote::class => new FakeFallback]);

    fakeApi()->sendRequest(new FakeScrape('https://a.test', renderJs: true));

    RomaHttp::assertSent(FakeScrape::class);
    RomaHttp::assertSent(fn (FakeScrape $request) => $request->renderJs && $request->country === 'us');
    RomaHttp::assertSent(fn (FakeScrape $request, Request $http, ?Response $response) => $response?->status() === 200);
    RomaHttp::assertNotSent(FakeCreateNote::class);
    RomaHttp::assertNotSent(fn (FakeScrape $request) => $request->country === 'fr');

    expect(RomaHttp::sent(FakeScrape::class))->toEqual([new FakeScrape('https://a.test', renderJs: true)])
        ->and(fn () => RomaHttp::assertSent(FakeCreateNote::class))->toThrow(AssertionFailedError::class, 'An expected FakeCreateNote request was not sent.')
        ->and(fn () => RomaHttp::assertNotSent(FakeScrape::class))->toThrow(AssertionFailedError::class, 'An unexpected FakeScrape request was sent.');
});

it('sends a request and lists it through the facade', function () {
    RomaHttp::fake([FakeScrape::class => fakeResult()]);

    expect(RomaHttp::sendRequest(new FakeScrape('https://a.test'), [FakeResult::class], 'https://api.test/scrape'))->toEqual(fakeResult())
        ->and(RomaHttp::sent(FakeScrape::class))->toEqual([new FakeScrape('https://a.test')]);
});

it('forwards sendRequest() and sent() to an instance swapped into the facade', function () {
    RomaHttp::swap(new class(app()) extends TypedFactory
    {
        public function sendRequest(object $request, array $responses = [], ?string $url = null): object
        {
            return new FakeFallback;
        }

        public function sent(string $class): array
        {
            return [new FakeScrape('https://swapped.test')];
        }
    });

    expect(RomaHttp::sendRequest(new FakeScrape('https://a.test')))->toEqual(new FakeFallback)
        ->and(RomaHttp::sent(FakeScrape::class))->toEqual([new FakeScrape('https://swapped.test')]);
});

it('passes a callback typed with Laravel\'s request to Http::assertSent()', function () {
    RomaHttp::fake([FakeScrape::class => fakeResult()]);

    fakeApi()->sendRequest(new FakeScrape('https://a.test'));

    RomaHttp::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.test/scrape'));
    RomaHttp::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    RomaHttp::assertSentCount(1);
});

it('records the request object for requests faked by URL', function () {
    Http::fake(['api.test/*' => Http::response(fakeResult()->content)]);

    fakeApi()->sendRequest(new FakeScrape('https://a.test'), [Response::class]);

    RomaHttp::assertSent(fn (FakeScrape $request) => $request->url === 'https://a.test');
});
