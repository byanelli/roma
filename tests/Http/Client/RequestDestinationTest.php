<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\DateFormat;
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Facades\RomaHttp;
use BYanelli\Roma\Http\Attributes\Body;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Path;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Client\Attributes\AsForm;
use BYanelli\Roma\Http\Client\Attributes\AsMultipart;
use BYanelli\Roma\Http\Client\Attributes\Delete;
use BYanelli\Roma\Http\Client\Attributes\Get;
use BYanelli\Roma\Http\Client\Attributes\Head;
use BYanelli\Roma\Http\Client\Attributes\Patch;
use BYanelli\Roma\Http\Client\Attributes\Post;
use BYanelli\Roma\Http\Client\Attributes\Put;
use BYanelli\Roma\Http\Client\Attributes\Url;
use BYanelli\Roma\Http\Client\Contracts\TypedFactory;
use BYanelli\Roma\Http\Client\TypedPendingRequest;
use BYanelli\Roma\Missing;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

enum ClientColor: string
{
    case Red = 'red';
    case Blue = 'blue';
}

final readonly class ClientNoBody {}

final readonly class ClientFilter
{
    public function __construct(public string $status, public int $min = 1) {}
}

#[Post('/teams/{team}/videos/{videoId}')]
final readonly class ClientCreateVideo
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $team,
        #[Path('videoId')] public int $id,
        #[Body] public string $title,
        #[Body('meta.color')] public ClientColor $color,
        #[Body] public array $tags,
        #[Query] public bool $notify,
        #[Query('filter')] public ClientFilter $filter,
        #[Header('X-Request-Id')] public string $requestId,
        #[Body, DateFormat('Y-m-d')] public DateTimeImmutable $publishAt,
    ) {}
}

#[Get('/search')]
final readonly class ClientSearch
{
    public function __construct(
        #[Query] public string $q,
        #[Query('per_page')] public int $perPage = 10,
        #[Query] public string|Missing|null $cursor = null,
        #[Query] public bool $exact = false,
        #[Header('X-Trace')] public ?string $trace = null,
    ) {}
}

#[Get('/files/{path}')]
final readonly class ClientGetFile
{
    public function __construct(public string $path) {}
}

#[Get('/videos')]
final readonly class ClientListVideos
{
    public function __construct(#[Url] public ?string $next = null) {}
}

#[Post('/login')]
final readonly class ClientLogin
{
    public function __construct(
        #[Body] public string $user,
        #[Body, SensitiveParameter] public string $password,
        #[Header('X-Api-Key'), SensitiveParameter] public string $apiKey,
    ) {}
}

#[Post('/subscribe'), AsForm]
final readonly class ClientSubscribe
{
    public function __construct(#[Body] public string $email, #[Body] public bool $daily) {}
}

#[Post('/upload')]
final readonly class ClientUpload
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        #[Body] public UploadedFile $file,
        #[Body] public string $caption,
        #[Body] public array $tags = [],
    ) {}
}

#[Put('/notes'), AsMultipart]
final readonly class ClientMultipartNote
{
    public function __construct(#[Body] public string $text) {}
}

#[Delete('/notes/{id}')]
final readonly class ClientDeleteNote
{
    public function __construct(public int $id) {}
}

#[Patch('/notes/{id}')]
final class ClientRenameNote
{
    #[Query]
    public string $mode {
        get => strtoupper($this->title);
    }

    public function __construct(public int $id, #[Body] public string $title) {}
}

#[Head('/ping')]
final readonly class ClientPing {}

final readonly class ClientVideo
{
    public function __construct(public int $id, #[Key('snippet.title')] public string $title) {}
}

final readonly class ClientVideoWithMeta
{
    public function __construct(
        public int $id,
        #[Header('X-RateLimit-Remaining')] public int $remaining,
        #[Status] public int $status,
        public ?string $missing = null,
    ) {}
}

function clientCreateVideo(): ClientCreateVideo
{
    return new ClientCreateVideo(
        team: 'a b/c',
        id: 7,
        title: 'Hi',
        color: ClientColor::Red,
        tags: ['x', 'y'],
        notify: true,
        filter: new ClientFilter('live'),
        requestId: 'r1',
        publishAt: new DateTimeImmutable('2026-01-02T03:04:05Z'),
    );
}

function lastClientRequest(): Request
{
    $recorded = Http::recorded();

    expect($recorded)->not->toBeEmpty();

    return $recorded->last()[0];
}

beforeEach(fn () => Http::preventStrayRequests());

it('sends each property to its destination', function () {
    Http::fake(['api.test/*' => Http::response(['id' => 7, 'snippet' => ['title' => 'Hi']], 201)]);

    $video = RomaHttp::baseUrl('https://api.test')->sendRequest(clientCreateVideo(), [ClientVideo::class]);

    $request = lastClientRequest();

    expect($video)->toEqual(new ClientVideo(7, 'Hi'))
        ->and($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.test/teams/a%20b%2Fc/videos/7?notify=true&filter%5Bstatus%5D=live&filter%5Bmin%5D=1')
        ->and($request->header('X-Request-Id'))->toBe(['r1'])
        ->and($request->header('Content-Type'))->toBe(['application/json'])
        ->and(json_decode($request->body(), true))->toBe([
            'title' => 'Hi',
            'meta' => ['color' => 'red'],
            'tags' => ['x', 'y'],
            'publishAt' => '2026-01-02',
        ]);
});

it('sends a GET request with its query and no body', function () {
    Http::fake(['api.test/*' => Http::response([])]);

    RomaHttp::baseUrl('https://api.test')->sendRequest(new ClientSearch('roma'), [ClientNoBody::class]);

    $request = lastClientRequest();

    expect($request->method())->toBe('GET')
        ->and($request->url())->toBe('https://api.test/search?q=roma&per_page=10&exact=false')
        ->and($request->body())->toBe('')
        ->and($request->hasHeader('X-Trace'))->toBeFalse();
});

it('merges the query with the pending request\'s query parameters', function () {
    Http::fake(['api.test/*' => Http::response([])]);

    RomaHttp::baseUrl('https://api.test')
        ->withQueryParameters(['key' => 'k1', 'per_page' => 50])
        ->sendRequest(new ClientSearch('roma', cursor: 'c2'), [ClientNoBody::class]);

    expect(lastClientRequest()->url())->toBe('https://api.test/search?key=k1&per_page=10&q=roma&cursor=c2&exact=false');
});

it('escapes a value bound to a path parameter by name', function () {
    Http::fake(['api.test/*' => Http::response([])]);

    RomaHttp::baseUrl('https://api.test')->sendRequest(new ClientGetFile('../etc/passwd?x=1'), [ClientNoBody::class]);

    expect(lastClientRequest()->url())->toBe('https://api.test/files/..%2Fetc%2Fpasswd%3Fx%3D1');
});

it('takes the URL from a #[Url] property, else from the class', function () {
    Http::fake(['api.test/*' => Http::response([])]);
    $api = fn () => RomaHttp::baseUrl('https://api.test')->withQueryParameters(['key' => 'k1']);

    $api()->sendRequest(new ClientListVideos('https://api.test/videos?page=2&tags[0]=a'), [ClientNoBody::class]);
    expect(lastClientRequest()->url())->toBe('https://api.test/videos?key=k1&page=2&tags%5B0%5D=a');

    $api()->sendRequest(new ClientListVideos, [ClientNoBody::class]);
    expect(lastClientRequest()->url())->toBe('https://api.test/videos?key=k1');
});

it('takes the URL from sendRequest() before the request object', function () {
    Http::fake(['callback.test/*' => Http::response([])]);

    RomaHttp::sendRequest(new ClientListVideos('https://api.test/videos?page=2'), [ClientNoBody::class], url: 'https://callback.test/hook');

    expect(lastClientRequest()->url())->toBe('https://callback.test/hook');
});

it('sends #[\SensitiveParameter] values', function () {
    Http::fake(['api.test/*' => Http::response([])]);

    RomaHttp::baseUrl('https://api.test')->sendRequest(new ClientLogin('bill', 'hunter2', 'key_1'), [ClientNoBody::class]);

    $request = lastClientRequest();

    expect(json_decode($request->body(), true))->toBe(['user' => 'bill', 'password' => 'hunter2'])
        ->and($request->header('X-Api-Key'))->toBe(['key_1']);
});

it('sends a form body', function () {
    Http::fake(['api.test/*' => Http::response([])]);

    RomaHttp::baseUrl('https://api.test')->sendRequest(new ClientSubscribe('a@b.test', true), [ClientNoBody::class]);

    $request = lastClientRequest();

    expect($request->isForm())->toBeTrue()
        ->and($request->body())->toBe('email=a%40b.test&daily=1');
});

it('sends a request with a file property as multipart', function () {
    Http::fake(['api.test/*' => Http::response([])]);
    $file = UploadedFile::fake()->createWithContent('clip.txt', 'hello');

    RomaHttp::baseUrl('https://api.test')->sendRequest(new ClientUpload($file, 'Clip', ['a', 'b']), [ClientNoBody::class]);

    $request = lastClientRequest();

    expect($request->isMultipart())->toBeTrue()
        ->and($request->hasFile('file', filename: 'clip.txt'))->toBeTrue()
        ->and($request->body())->toContain("filename=\"clip.txt\"\r\nContent-Length: 5\r\nContent-Type: text/plain\r\n\r\nhello")
        ->and($request->body())->toContain('name="caption"')
        ->and($request->body())->toContain('name="tags[1]"');
});

it('sends a multipart body with #[AsMultipart]', function () {
    Http::fake(['api.test/*' => Http::response([])]);

    RomaHttp::baseUrl('https://api.test')->sendRequest(new ClientMultipartNote('hi'), [ClientNoBody::class]);

    $request = lastClientRequest();

    expect($request->method())->toBe('PUT')
        ->and($request->isMultipart())->toBeTrue()
        ->and($request->data())->toBe([['name' => 'text', 'contents' => 'hi']]);
});

it('sends DELETE, PATCH and HEAD requests', function () {
    Http::fake(['api.test/*' => Http::response([])]);
    $api = fn () => RomaHttp::baseUrl('https://api.test');

    $api()->sendRequest(new ClientDeleteNote(3), [ClientNoBody::class]);
    expect(lastClientRequest()->method())->toBe('DELETE')
        ->and(lastClientRequest()->url())->toBe('https://api.test/notes/3');

    $api()->sendRequest(new ClientRenameNote(3, 'todo'), [ClientNoBody::class]);
    expect(lastClientRequest()->method())->toBe('PATCH')
        ->and(lastClientRequest()->url())->toBe('https://api.test/notes/3?mode=TODO')
        ->and(json_decode(lastClientRequest()->body(), true))->toBe(['title' => 'todo']);

    $api()->sendRequest(new ClientPing, [ClientNoBody::class]);
    expect(lastClientRequest()->method())->toBe('HEAD');
});

it('maps the body, headers and status of a 2xx response', function () {
    Http::fake(['api.test/*' => Http::response(['id' => 7], 202, ['X-RateLimit-Remaining' => '41'])]);

    $video = RomaHttp::baseUrl('https://api.test')->sendRequest(new ClientSearch('roma'), [ClientVideoWithMeta::class]);

    expect($video)->toEqual(new ClientVideoWithMeta(7, 41, 202));
});

it('reads a response header in any case', function () {
    Http::fake(['api.test/*' => Http::response(['id' => 7], 200, ['x-ratelimit-remaining' => '3'])]);

    expect(RomaHttp::baseUrl('https://api.test')->sendRequest(new ClientSearch('roma'), [ClientVideoWithMeta::class])->remaining)->toBe(3);
});

it('returns Laravel\'s response for Response::class', function () {
    Http::fake(['api.test/*' => Http::response('<xml/>')]);

    $response = RomaHttp::baseUrl('https://api.test')->sendRequest(new ClientSearch('roma'), [Response::class]);

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->body())->toBe('<xml/>');
});

it('keeps a request\'s attributes, headers and encoding off the pending request it was sent with', function () {
    Http::fake(['api.test/*' => Http::response([])]);
    $api = RomaHttp::baseUrl('https://api.test');

    $api->sendRequest(new ClientSubscribe('a@b.test', true), [ClientNoBody::class]);
    $api->sendRequest(clientCreateVideo(), [ClientNoBody::class]);
    $api->sendRequest(new ClientSearch('roma'), [ClientNoBody::class]);

    [$form, $json, $get] = Http::recorded()->map(fn (array $pair) => $pair[0])->all();

    expect($form->isForm())->toBeTrue()
        ->and($json->isJson())->toBeTrue()
        ->and($get->hasHeader('X-Request-Id'))->toBeFalse()
        ->and($get->attributes()[TypedPendingRequest::REQUEST_ATTRIBUTE])->toEqual(new ClientSearch('roma'));
});

it('applies Http::fake() and preventStrayRequests()', function () {
    Http::fake(['other.test/*' => Http::response([])]);

    expect(fn () => RomaHttp::baseUrl('https://api.test')->sendRequest(new ClientSearch('roma'), [ClientNoBody::class]))
        ->toThrow(StrayRequestException::class);
});

it('returns a typed pending request from configuring methods and passes other calls through', function () {
    Http::fake(['api.test/*' => Http::response(['ok' => true])]);
    $factory = app(TypedFactory::class);

    $pending = $factory->baseUrl('https://api.test')->withToken('t')->timeout(5)->when(true, fn ($request) => $request->acceptJson());

    expect($pending)->toBeInstanceOf(TypedPendingRequest::class)
        ->and($pending->get('/plain')->json())->toBe(['ok' => true])
        ->and($factory->preventingStrayRequests())->toBeTrue()
        ->and(lastClientRequest()->header('Authorization'))->toBe(['Bearer t']);
});

it('rejects a request class it cannot send', function (string $class, string $message) {
    $request = new ReflectionClass($class)->newInstanceWithoutConstructor();

    expect(fn () => RomaHttp::sendRequest($request, [ClientNoBody::class]))->toThrow(RuntimeException::class, $message);
})->with([
    'no method attribute' => [ClientNoMethod::class, 'Roma request ClientNoMethod has no method attribute'],
    'no destination' => [ClientNoDestination::class, 'Roma request property "ClientNoDestination::$name" has no destination. Add #[Body], #[Query], #[Header] or #[Path].'],
    'GET with a body' => [ClientGetWithBody::class, 'Roma request ClientGetWithBody is sent with GET, which has no body, but $name has #[Body]'],
    'unbound path parameter' => [ClientUnboundPath::class, 'Roma request ClientUnboundPath has no property for the path parameter {id}'],
    '#[Path] not in the path' => [ClientExtraPath::class, 'Roma request ClientExtraPath has a #[Path] property for "slug", but its path has no {slug}'],
    '#[Status] on a request' => [ClientRequestWithStatus::class, 'has #[Status], but a request has no status'],
    'form and multipart' => [ClientFormAndMultipart::class, 'has both #[AsForm] and #[AsMultipart]'],
    'form with a file' => [ClientFormWithFile::class, 'has #[AsForm] and a file property'],
    'file outside the body' => [ClientFileInQuery::class, 'is a file, which can only be sent in the body'],
]);

it('throws for a missing path value without the value or URL in the message', function () {
    expect(fn () => RomaHttp::sendRequest(new ClientOptionalPath, [ClientNoBody::class]))
        ->toThrow(RuntimeException::class, 'Roma request ClientOptionalPath has no value for the path parameter {id}.');
});

it('throws for a request with no URL', function () {
    expect(fn () => RomaHttp::sendRequest(new ClientNoUrl, [ClientNoBody::class]))
        ->toThrow(RuntimeException::class, 'Roma request ClientNoUrl has no URL');
});

final readonly class ClientNoMethod {}

#[Post('/x')]
final readonly class ClientNoDestination
{
    public function __construct(public string $name) {}
}

#[Get('/x')]
final readonly class ClientGetWithBody
{
    public function __construct(#[Body] public string $name) {}
}

#[Get('/x/{id}')]
final readonly class ClientUnboundPath
{
    public function __construct(#[Query] public string $id) {}
}

#[Get('/x')]
final readonly class ClientExtraPath
{
    public function __construct(#[Path] public string $slug) {}
}

#[Get('/x')]
final readonly class ClientRequestWithStatus
{
    public function __construct(#[Status] public int $status) {}
}

#[Post('/x'), AsForm, AsMultipart]
final readonly class ClientFormAndMultipart {}

#[Post('/x'), AsForm]
final readonly class ClientFormWithFile
{
    public function __construct(#[Body] public UploadedFile $file) {}
}

#[Post('/x')]
final readonly class ClientFileInQuery
{
    public function __construct(#[Query] public UploadedFile $file) {}
}

#[Get('/x/{id}')]
final class ClientOptionalPath
{
    #[Optional]
    public int $id;
}

#[Get]
final readonly class ClientNoUrl
{
    public function __construct(#[Url] public ?string $next = null) {}
}
