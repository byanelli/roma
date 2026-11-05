<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Delimited;
use BYanelli\Roma\Attributes\RequiredOnCreate;
use BYanelli\Roma\Http\Attributes\Accessors\Segments;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Request\Injection\Request as RomaRequest;
use BYanelli\Roma\Http\Request\Injection\RequestBody;
use BYanelli\Roma\Http\Request\Injection\RequestQuery;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;

readonly class EmptyRequestAddress
{
    public function __construct(public string $city) {}
}

readonly class EmptyRequestOptions
{
    public function __construct(public string $color = 'gray') {}
}

#[RomaRequest]
readonly class EmptyRequestObject
{
    /**
     * @param  list<string>  $tags
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public array $tags,
        public array $counts,
        public EmptyRequestOptions $options,
    ) {}
}

readonly class EmptyBodyObject
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public array $tags,
        public EmptyRequestAddress|Missing $address,
    ) {}
}

#[RomaRequest]
readonly class NonEmptyRequestObject
{
    /**
     * @param  non-empty-list<string>  $tags
     */
    public function __construct(public array $tags) {}
}

readonly class EmptyQueryObject
{
    /**
     * @param  list<string>  $tags
     * @param  non-empty-list<string>  $required
     */
    public function __construct(
        #[Delimited]
        public array $tags,

        #[Delimited]
        public array $required = ['x'],
    ) {}
}

#[RomaRequest]
readonly class EmptyQueryAttributeObject
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        #[Query]
        #[Delimited]
        public array $tags,
    ) {}
}

#[RomaRequest]
readonly class EmptySegmentsObject
{
    /** @var list<string> */
    #[Segments]
    public array $segments;
}

#[RomaRequest]
readonly class EmptyOperationRequest
{
    /**
     * @param  list<string>  $tags
     * @param  non-empty-list<string>  $codes
     */
    public function __construct(
        #[RequiredOnCreate]
        public array|Missing $tags,

        #[RequiredOnCreate]
        public array|Missing $codes,
    ) {}
}

beforeEach(function () {
    Route::post('/empty/request', fn (EmptyRequestObject $request) => response()->json($request));
    Route::post('/empty/non-empty', fn (NonEmptyRequestObject $request) => response()->json($request));
    Route::post('/empty/body', fn (
        #[RequestBody]
        EmptyBodyObject $body,
    ) => response()->json($body));
    Route::get('/empty/query', fn (
        #[RequestQuery]
        EmptyQueryObject $query,
    ) => response()->json($query));
    Route::get('/empty/query-attribute', fn (EmptyQueryAttributeObject $query) => response()->json($query));
    Route::get('/', fn (EmptySegmentsObject $request) => response()->json($request));
    Route::match(['POST', 'PUT'], '/empty/operation', fn (EmptyOperationRequest $request) => response()->noContent());
    Route::post('/empty/precognitive', fn (EmptyRequestObject $request) => response()->noContent())
        ->middleware(HandlePrecognitiveRequests::class);
    Route::post('/empty/precognitive-non-empty', fn (NonEmptyRequestObject $request) => response()->noContent())
        ->middleware(HandlePrecognitiveRequests::class);
});

it('accepts empty JSON values under #[Request]', function () {
    /** @var TestCase $this */
    $this->call('POST', '/empty/request', server: ['CONTENT_TYPE' => 'application/json'], content: '{"tags":[],"counts":{},"options":{}}')
        ->assertOk()
        ->assertExactJson(['tags' => [], 'counts' => [], 'options' => ['color' => 'gray']]);
});

it('reports absent and null values under #[Request]', function () {
    /** @var TestCase $this */
    $this->postJson('/empty/request', ['tags' => null])
        ->assertUnprocessable()
        ->assertJsonPath('errors', [
            'input.tags' => ['The input.tags field is required.'],
            'input.counts' => ['The input.counts field is required.'],
            'input.options' => ['The input.options field is required.'],
        ]);
});

it('rejects [] for a non-empty list under #[Request]', function () {
    /** @var TestCase $this */
    $this->postJson('/empty/non-empty', ['tags' => []])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['input.tags' => ['The input.tags field is required.']]);
});

it('accepts [] under #[RequestBody] and reports a nested object\'s members under bare keys', function () {
    /** @var TestCase $this */
    $this->postJson('/empty/body', ['tags' => []])->assertOk()->assertJsonPath('tags', []);

    $this->postJson('/empty/body', ['tags' => [], 'address' => []])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['address.city' => ['The address.city field is required.']]);

    $this->postJson('/empty/body', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['tags' => ['The tags field is required.']]);
});

it('maps a delimited query value with no items to [] under #[RequestQuery]', function () {
    /** @var TestCase $this */
    $this->getJson('/empty/query?tags=,')->assertOk()->assertJsonPath('tags', []);

    $this->getJson('/empty/query?tags=')
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['tags' => ['The tags field is required.']]);

    $this->getJson('/empty/query?tags=a&required=,')
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['required' => ['The required field must not be empty.']]);
});

it('treats #[Query] with #[Delimited] the same under #[Request]', function () {
    /** @var TestCase $this */
    $this->getJson('/empty/query-attribute?tags=,')->assertOk()->assertExactJson(['tags' => []]);

    $this->getJson('/empty/query-attribute')
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['query.tags' => ['The query.tags field is required.']]);
});

it('maps a request accessor\'s empty array', function () {
    /** @var TestCase $this */
    $this->getJson('/')->assertOk()->assertExactJson(['segments' => []]);
});

it('requires a #[RequiredOnCreate] array on a POST, where [] passes for a plain list', function () {
    /** @var TestCase $this */
    $this->postJson('/empty/operation', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors', [
            'input.tags' => ['The input.tags field is required.'],
            'input.codes' => ['The input.codes field is required.'],
        ]);

    $this->postJson('/empty/operation', ['tags' => [], 'codes' => []])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['input.codes' => ['The input.codes field is required.']]);

    $this->postJson('/empty/operation', ['tags' => [], 'codes' => ['a']])->assertNoContent();
});

it('leaves a #[RequiredOnCreate] array optional on a PUT, where [] fails for a non-empty list', function () {
    /** @var TestCase $this */
    $this->putJson('/empty/operation', [])->assertNoContent();
    $this->putJson('/empty/operation', ['tags' => []])->assertNoContent();

    $this->putJson('/empty/operation', ['codes' => []])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['input.codes' => ['The input.codes field must not be empty.']]);
});

it('accepts empty values in a precognitive request', function () {
    /** @var TestCase $this */
    $this->withPrecognition()
        ->postJson('/empty/precognitive', ['tags' => [], 'counts' => [], 'options' => []])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');
});

it('reports absent values and non-empty types under bare keys in a precognitive request', function () {
    /** @var TestCase $this */
    $this->withPrecognition()
        ->postJson('/empty/precognitive', ['counts' => [], 'options' => []])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['tags' => ['The tags field is required.']]);

    $this->withPrecognition()
        ->postJson('/empty/precognitive-non-empty', ['tags' => []])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['tags' => ['The tags field is required.']]);
});

it('narrows empty values to Precognition-Validate-Only', function () {
    /** @var TestCase $this */
    $this->withPrecognition()
        ->withHeader('Precognition-Validate-Only', 'tags')
        ->postJson('/empty/precognitive', ['tags' => []])
        ->assertNoContent();
});
