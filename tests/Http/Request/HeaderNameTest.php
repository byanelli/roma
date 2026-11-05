<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Headers\Authorization;
use BYanelli\Roma\Http\Attributes\Headers\ContentType;
use BYanelli\Roma\Http\Attributes\Input;
use BYanelli\Roma\Http\Request\Injection\Request as RomaRequest;
use BYanelli\Roma\Http\Request\Sources;
use BYanelli\Roma\Tests\TestCase;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

readonly class InnerCapitalHeaderRequest
{
    public function __construct(
        #[Header('X-RateLimit-Remaining')]
        public string $remaining,
    ) {}
}

readonly class AcronymHeaderRequest
{
    public function __construct(
        #[Header('X-API-Key')]
        public string $apiKey,
    ) {}
}

readonly class DottedHeaderRequest
{
    public function __construct(
        #[Header('X-Request.Id')]
        #[Rule('max:5')]
        public string $id,
    ) {}
}

readonly class UnderscoreHeaderRequest
{
    public function __construct(
        #[Header('x_ratelimit_remaining')]
        public string $remaining,
    ) {}
}

readonly class SimilarHeadersRequest
{
    public function __construct(
        #[Header('X-RateLimit')]
        public string $noSeparator,

        #[Header('X-Rate-Limit')]
        public string $separator,
    ) {}
}

readonly class InnerCapitalTwiceRequest
{
    public function __construct(
        #[Header('X-RateLimit')]
        public string $first,

        #[Header('x-ratelimit')]
        public string $second,
    ) {}
}

readonly class DottedHeaderTwiceRequest
{
    public function __construct(
        #[Header('X-Request.Id')]
        public string $first,

        #[Header('x-request.ID')]
        public string $second,
    ) {}
}

readonly class CamelCaseHeaderRequest
{
    public function __construct(
        #[Header('ContentType')]
        public string $contentType,
    ) {}
}

readonly class OptionalCamelCaseHeaderRequest
{
    public function __construct(
        #[Header('ContentType')]
        public ?string $contentType = null,
    ) {}
}

#[ContentType('application/json')]
readonly class ClassLevelContentTypeRequest
{
    public function __construct(
        #[Input]
        public string $name = 'x',
    ) {}
}

readonly class ContentTypePropertyRequest
{
    public function __construct(
        #[ContentType]
        public string $contentType,

        #[Authorization]
        public string $authorization,
    ) {}
}

#[RomaRequest]
readonly class RoutedInnerCapitalHeaderRequest
{
    public function __construct(
        public string $name,

        #[Header('X-RateLimit-Remaining')]
        public string $remaining,

        #[Header('X-Request.Id')]
        public string $id,
    ) {}
}

class InnerCapitalHeaderResponse
{
    public function __construct(
        #[Header('X-RateLimit-Remaining')]
        public string $remaining,

        #[Header('X-Request.Id')]
        public string $id,
    ) {}
}

it('reads a header with an inner capital or a dot, however the request capitalizes it', function (string $class, string $sent, string $property) {
    /** @var TestCase $this */
    $this->setRequest(headers: [$sent => '42']);

    expect($this->mapRequest($class)->{$property})->toBe('42');
})->with([
    'inner capital' => [InnerCapitalHeaderRequest::class, 'X-RateLimit-Remaining', 'remaining'],
    'inner capital, sent in lowercase' => [InnerCapitalHeaderRequest::class, 'x-ratelimit-remaining', 'remaining'],
    'inner capital, sent in uppercase' => [InnerCapitalHeaderRequest::class, 'X-RATELIMIT-REMAINING', 'remaining'],
    'inner capital, declared with underscores' => [UnderscoreHeaderRequest::class, 'X-RateLimit-Remaining', 'remaining'],
    'acronym' => [AcronymHeaderRequest::class, 'X-API-Key', 'apiKey'],
    'dot' => [DottedHeaderRequest::class, 'X-Request.Id', 'id'],
    'dot, sent in lowercase' => [DottedHeaderRequest::class, 'x-request.id', 'id'],
    'dot, sent in uppercase' => [DottedHeaderRequest::class, 'X-REQUEST.ID', 'id'],
]);

it('reads a name that the server bag keeps with its hyphens', function () {
    /** @var TestCase $this */
    $request = new Request(server: ['HTTP_X-RateLimit-Remaining' => '42']);
    $this->app->bind('request', fn () => $request);

    expect($this->mapRequest(InnerCapitalHeaderRequest::class)->remaining)->toBe('42');
});

it('keeps the real header name in error keys', function (string $class, array $headers, string $errorKey) {
    /** @var TestCase $this */
    $this->setRequest(headers: $headers);

    try {
        $this->mapRequest($class);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe([$errorKey]);
    }
})->with([
    'missing, inner capital' => [InnerCapitalHeaderRequest::class, [], 'header.X-RateLimit-Remaining'],
    'missing, acronym' => [AcronymHeaderRequest::class, [], 'header.X-API-Key'],
    'missing, dot' => [DottedHeaderRequest::class, [], 'header.X-Request.Id'],
    'rule fails, dot' => [DottedHeaderRequest::class, ['X-Request.Id' => 'too long'], 'header.X-Request.Id'],
]);

it('rejects two properties on one header in different cases', function (string $class, string $first, string $second, string $key) {
    /** @var TestCase $this */
    $this->setRequest();

    expect(fn () => $this->mapRequest($class))->toThrow(
        RuntimeException::class,
        "the same key: \"header.{$first}\" and \"header.{$second}\" are both read as \"header.{$key}\"",
    );
})->with([
    'inner capital' => [InnerCapitalTwiceRequest::class, 'X-RateLimit', 'x-ratelimit', 'x_ratelimit'],
    'dot' => [DottedHeaderTwiceRequest::class, 'X-Request.Id', 'x-request.ID', 'x_request.id'],
]);

it('treats names that differ by a separator as different headers', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['X-RateLimit' => 'one', 'X-Rate-Limit' => 'two']);

    $request = $this->mapRequest(SimilarHeadersRequest::class);

    expect($request->noSeparator)->toBe('one')
        ->and($request->separator)->toBe('two');
});

it('does not read Content-Type for a header declared in camelCase', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json']);

    expect(fn () => $this->mapRequest(CamelCaseHeaderRequest::class))->toThrow(ValidationException::class)
        ->and($this->mapRequest(OptionalCamelCaseHeaderRequest::class)->contentType)->toBeNull();
});

it('still applies a class-level content type constraint', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json']);

    expect($this->mapRequest(ClassLevelContentTypeRequest::class)->name)->toBe('x');

    $this->setRequest(headers: ['Content-Type' => 'text/plain']);

    try {
        $this->mapRequest(ClassLevelContentTypeRequest::class);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['header.Content-Type']);
    }
});

it('reads the pre-made header attributes', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'text/plain', 'Authorization' => 'Bearer abc']);

    $request = $this->mapRequest(ContentTypePropertyRequest::class);

    expect($request->contentType)->toBe('text/plain')
        ->and($request->authorization)->toBe('Bearer abc');
});

it('normalizes names the same way in the attribute and the source', function (string $name, string $key) {
    expect(new Header($name)->getKey())->toBe($key)
        ->and(new Sources\Header()->normalizeKey($name))->toBe($key);
})->with([
    ['Content-Type', 'content_type'],
    ['X-RateLimit-Remaining', 'x_ratelimit_remaining'],
    ['X-API-Key', 'x_api_key'],
    ['X-Request.Id', 'x_request.id'],
    ['x_flag', 'x_flag'],
]);

it('writes the real header name when serializing and in TypeScript', function () {
    expect(Serializer::serialize(new InnerCapitalHeaderResponse('42', 'abc'))->headers)
        ->toBe(['X-RateLimit-Remaining' => '42', 'X-Request.Id' => 'abc']);

    expect(new TypeScriptGenerator([InnerCapitalHeaderRequest::class, DottedHeaderRequest::class], [])->generate())
        ->toContain("'X-RateLimit-Remaining': string;")
        ->toContain("'X-Request.Id': string;");

    expect(new TypeScriptGenerator([], [InnerCapitalHeaderResponse::class])->generate())
        ->toContain("'X-RateLimit-Remaining': string;")
        ->toContain("'X-Request.Id': string;");
});

it('reads the headers of a routed request and reports them under their real names', function () {
    /** @var TestCase $this */
    Route::post('/headers', fn (RoutedInnerCapitalHeaderRequest $request) => response()->json([$request->remaining, $request->id]));

    $this->withHeaders(['x-ratelimit-remaining' => '42', 'X-REQUEST.ID' => 'abc'])
        ->postJson('/headers', ['name' => 'Bill'])
        ->assertOk()
        ->assertExactJson(['42', 'abc']);

    $this->flushHeaders();

    $response = $this->postJson('/headers', ['name' => 'Bill']);

    $response->assertUnprocessable();

    expect(array_keys($response->json('errors')))->toBe(['header.X-RateLimit-Remaining', 'header.X-Request.Id']);
});

it('skips these headers during a precognitive request', function () {
    /** @var TestCase $this */
    Route::post('/headers', fn (RoutedInnerCapitalHeaderRequest $request) => response()->json(['ran' => true]))
        ->middleware(HandlePrecognitiveRequests::class);

    $this->withPrecognition()
        ->postJson('/headers', ['name' => 'Bill'])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');
});
