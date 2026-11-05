<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Attributes\RejectUnknownKeys;
use BYanelli\Roma\Http\Attributes\Accessors\BearerToken;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Headers\Authorization;
use BYanelli\Roma\Http\Attributes\Headers\ContentType;
use BYanelli\Roma\Http\Enums\AuthScheme;
use BYanelli\Roma\Http\Request\Injection\Request as RomaRequest;
use BYanelli\Roma\Http\Values\Authorization as AuthorizationValue;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderBag;

readonly class HeaderBagRequest
{
    public function __construct(
        #[Header('X-Tenant')]
        public string $tenant,
    ) {}
}

readonly class OptionalHeaderBagRequest
{
    public function __construct(
        #[Header('X-Tenant')]
        public ?string $tenant = null,
    ) {}
}

readonly class HeaderBagAttributesRequest
{
    public function __construct(
        #[ContentType]
        public string $contentType,

        #[Authorization]
        public string $authorization,

        #[Header('Content-Length')]
        public string $contentLength,
    ) {}
}

readonly class HeaderBagAuthorizationRequest
{
    public function __construct(
        public AuthorizationValue $authorization,
    ) {}
}

readonly class HeaderBagRepeatedAuthorizationRequest
{
    public function __construct(
        #[Authorization]
        public string $header,

        #[BearerToken]
        public ?string $token,
    ) {}
}

readonly class HeaderBagPhpAuthRequest
{
    public function __construct(
        #[Header('PHP_AUTH_USER')]
        public string $user,

        #[Header('PHP-AUTH-PW')]
        public string $password,
    ) {}
}

#[ContentType(ContentType::APPLICATION_JSON)]
readonly class HeaderBagContentTypeConstraintRequest
{
    public function __construct(
        #[Optional]
        public string $name = '',
    ) {}
}

#[RejectUnknownKeys]
readonly class HeaderBagStrictRequest
{
    public function __construct(
        #[Header('X-Tenant')]
        public string $tenant,

        public string $name,
    ) {}
}

#[RomaRequest]
readonly class RoutedHeaderBagRequest
{
    public function __construct(
        #[Header('X-Tenant')]
        public string $tenant,

        public string $name,
    ) {}
}

class SetsTenantHeader
{
    public function handle(Request $request, Closure $next): mixed
    {
        $request->headers->set('X-Tenant', 'acme');

        return $next($request);
    }
}

function bindHeaderBagRequest(array $headers): Request
{
    $request = new Request;
    $request->headers = new HeaderBag($headers);
    app()->bind('request', fn () => $request);

    return $request;
}

it('reads a header set on the request after it was created', function () {
    /** @var TestCase $this */
    $request = new Request(server: ['HTTP_X_TENANT' => 'from-server']);
    $this->app->bind('request', fn () => $request);

    $request->headers->set('X-Tenant', 'from-middleware');

    expect($this->mapRequest(HeaderBagRequest::class)->tenant)->toBe('from-middleware');
});

it('does not read a header removed from the request', function () {
    /** @var TestCase $this */
    $request = new Request(server: ['HTTP_X_TENANT' => 'from-server']);
    $this->app->bind('request', fn () => $request);

    $request->headers->remove('X-Tenant');

    expect($this->mapRequest(OptionalHeaderBagRequest::class)->tenant)->toBeNull();
});

it('reads a header that middleware sets in a routed request', function () {
    /** @var TestCase $this */
    Route::post('/header-bag', fn (RoutedHeaderBagRequest $request) => response()->json([$request->tenant]))
        ->middleware(SetsTenantHeader::class);

    $this->postJson('/header-bag', ['name' => 'Bill'])->assertOk()->assertExactJson(['acme']);
});

it('reads headers from a request with an empty server bag', function () {
    /** @var TestCase $this */
    bindHeaderBagRequest([
        'content-type' => 'text/plain',
        'authorization' => 'Bearer abc',
        'content-length' => '12',
    ]);

    $request = $this->mapRequest(HeaderBagAttributesRequest::class);

    expect($request->contentType)->toBe('text/plain')
        ->and($request->authorization)->toBe('Bearer abc')
        ->and($request->contentLength)->toBe('12');
});

it('parses the Authorization value object from a request with an empty server bag', function () {
    /** @var TestCase $this */
    bindHeaderBagRequest(['Authorization' => 'Bearer abc']);

    $authorization = $this->mapRequest(HeaderBagAuthorizationRequest::class)->authorization;

    expect($authorization->scheme)->toBe(AuthScheme::Bearer)
        ->and($authorization->credentials)->toBe('abc');
});

it('applies a class-level content type constraint to a request with an empty server bag', function () {
    /** @var TestCase $this */
    bindHeaderBagRequest(['Content-Type' => 'application/json']);

    expect($this->mapRequest(HeaderBagContentTypeConstraintRequest::class))->toBeInstanceOf(HeaderBagContentTypeConstraintRequest::class);

    bindHeaderBagRequest(['Content-Type' => 'text/plain']);

    try {
        $this->mapRequest(HeaderBagContentTypeConstraintRequest::class);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['header.Content-Type']);
    }
});

it('reads the server values that Symfony turns into headers', function (array $server, string $property, string $value) {
    /** @var TestCase $this */
    $this->app->bind('request', fn () => new Request(server: $server));

    expect($this->mapRequest(HeaderBagAttributesRequest::class)->{$property})->toBe($value);
})->with([
    'CONTENT_TYPE' => [['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '12', 'HTTP_AUTHORIZATION' => 'Bearer abc'], 'contentType', 'text/plain'],
    'CONTENT_LENGTH' => [['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '12', 'HTTP_AUTHORIZATION' => 'Bearer abc'], 'contentLength', '12'],
    'HTTP_AUTHORIZATION' => [['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '12', 'HTTP_AUTHORIZATION' => 'Bearer abc'], 'authorization', 'Bearer abc'],
    'REDIRECT_HTTP_AUTHORIZATION' => [['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '12', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc'], 'authorization', 'Bearer abc'],
    'PHP_AUTH_USER and PHP_AUTH_PW' => [['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '12', 'PHP_AUTH_USER' => 'ada', 'PHP_AUTH_PW' => 'pw'], 'authorization', 'Basic '.base64_encode('ada:pw')],
]);

it('reads the PHP_AUTH values under the names PHP gives them', function () {
    /** @var TestCase $this */
    $this->app->bind('request', fn () => new Request(server: ['PHP_AUTH_USER' => 'ada', 'PHP_AUTH_PW' => 'pw']));

    $request = $this->mapRequest(HeaderBagPhpAuthRequest::class);

    expect($request->user)->toBe('ada')
        ->and($request->password)->toBe('pw');
});

it('decodes Basic credentials from PHP_AUTH values', function () {
    /** @var TestCase $this */
    $this->app->bind('request', fn () => new Request(server: ['PHP_AUTH_USER' => 'ada', 'PHP_AUTH_PW' => 'pw']));

    $basic = $this->mapRequest(HeaderBagAuthorizationRequest::class)->authorization->basic();

    expect($basic?->username)->toBe('ada')
        ->and($basic?->password)->toBe('pw');
});

it('maps the value that $request->header() returns', function (Closure $build, ?string $expected) {
    /** @var TestCase $this */
    $request = $build();
    $this->app->bind('request', fn () => $request);

    expect($request->header('X-Tenant'))->toBe($expected)
        ->and($this->mapRequest(OptionalHeaderBagRequest::class)->tenant)->toBe($expected);
})->with([
    'a single header' => [fn () => new Request(server: ['HTTP_X_TENANT' => 'acme']), 'acme'],
    'a repeated header' => [function () {
        $request = new Request(server: ['HTTP_X_TENANT' => 'acme']);
        $request->headers->set('x-tenant', 'globex', replace: false);

        return $request;
    }, 'acme'],
    'several values set at once' => [fn () => bindHeaderBagRequest(['X-Tenant' => ['acme', 'globex']]), 'acme'],
    'a header set in middleware' => [function () {
        $request = new Request(server: ['HTTP_X_TENANT' => 'from-server']);
        $request->headers->set('X-Tenant', 'from-middleware');

        return $request;
    }, 'from-middleware'],
    'a header removed in middleware' => [function () {
        $request = new Request(server: ['HTTP_X_TENANT' => 'from-server']);
        $request->headers->remove('X-Tenant');

        return $request;
    }, null],
    'an empty server bag' => [fn () => bindHeaderBagRequest(['X-Tenant' => 'acme']), 'acme'],
    'an absent header' => [fn () => new Request, null],
    'an empty value' => [fn () => new Request(server: ['HTTP_X_TENANT' => '']), ''],
]);

it('gives a repeated Authorization header the value that bearerToken() reads', function () {
    /** @var TestCase $this */
    $request = new Request(server: ['HTTP_AUTHORIZATION' => 'Bearer first']);
    $request->headers->set('Authorization', 'Bearer second', replace: false);
    $this->app->bind('request', fn () => $request);

    $mapped = $this->mapRequest(HeaderBagRepeatedAuthorizationRequest::class);

    expect($mapped->header)->toBe($request->header('Authorization'))
        ->and($mapped->token)->toBe($request->bearerToken())
        ->and($mapped->token)->toBe('first')
        ->and($this->mapRequest(HeaderBagAuthorizationRequest::class)->authorization->credentials)->toBe('first');
});

it('applies a class-level content type constraint to the first value of a repeated header', function () {
    /** @var TestCase $this */
    bindHeaderBagRequest(['Content-Type' => ['application/json', 'text/plain']]);

    expect($this->mapRequest(HeaderBagContentTypeConstraintRequest::class))->toBeInstanceOf(HeaderBagContentTypeConstraintRequest::class);
});

it('treats a header without a value as absent', function (string|array|null $value) {
    /** @var TestCase $this */
    bindHeaderBagRequest(['X-Tenant' => $value]);

    expect($this->mapRequest(OptionalHeaderBagRequest::class)->tenant)->toBeNull();

    try {
        $this->mapRequest(HeaderBagRequest::class);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['header.X-Tenant' => ['The header.X-Tenant field is required.']]);
    }
})->with([
    'null' => [null],
    'no values' => [[]],
    'only a null value' => [[null]],
    'a null first value' => [[null, 'acme']],
]);

it('compares the bag\'s names as it does the server\'s', function (string $sent) {
    /** @var TestCase $this */
    bindHeaderBagRequest([$sent => 'acme']);

    expect($this->mapRequest(HeaderBagRequest::class)->tenant)->toBe('acme');
})->with(['X-Tenant', 'x-tenant', 'X_TENANT', 'x_tenant']);

it('never reports a header as an unknown key', function () {
    /** @var TestCase $this */
    $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_OTHER' => 'y'], content: '{"name":"Bill"}');
    $request->headers->set('X-Tenant', 'acme');
    $request->headers->set('X-Added', 'z');
    $this->app->bind('request', fn () => $request);

    expect($this->mapRequest(HeaderBagStrictRequest::class)->tenant)->toBe('acme');
});

it('leaves a middleware-set header out of a precognitive request', function () {
    /** @var TestCase $this */
    Route::post('/header-bag', fn (RoutedHeaderBagRequest $request) => response()->json(['ran' => true]))
        ->middleware([SetsTenantHeader::class, HandlePrecognitiveRequests::class]);

    $this->withPrecognition()
        ->postJson('/header-bag', ['name' => 'Bill'])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');
});
