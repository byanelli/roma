<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Body;
use BYanelli\Roma\Request\Attributes\Query;
use BYanelli\Roma\Request\ContextualBinding\Request as RomaRequest;
use BYanelli\Roma\Request\Enums\Method;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

readonly class TestQueryMethodEnum
{
    public Method $method;
}

it('infers the QUERY request method for a Method enum property', function () {
    /** @var TestCase $this */
    $request = Request::create('/', 'QUERY', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
    $this->app->bind('request', fn () => $request);

    $mapped = $this->mapRequest(TestQueryMethodEnum::class);

    $this->assertEquals(Method::Query, $mapped->method);
});

readonly class TestQueryMethodBody
{
    #[Body]
    public string $term;

    public int $page;
}

it('maps a JSON body sent with the QUERY method', function () {
    /** @var TestCase $this */
    // QUERY carries its input as request content, like POST, so the body bag
    // and the default input source both see it.
    $request = Request::create(
        '/',
        'QUERY',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['term' => 'roma', 'page' => 2]),
    );
    $this->app->bind('request', fn () => $request);

    $mapped = $this->mapRequest(TestQueryMethodBody::class);

    expect($mapped->term)->toBe('roma')
        ->and($mapped->page)->toBe(2);
});

it('maps a form-encoded body sent with the QUERY method', function () {
    /** @var TestCase $this */
    $request = Request::create('/', 'QUERY', ['term' => 'roma', 'page' => '2']);
    $this->app->bind('request', fn () => $request);

    $mapped = $this->mapRequest(TestQueryMethodBody::class);

    expect($mapped->term)->toBe('roma')
        ->and($mapped->page)->toBe(2);
});

readonly class TestQueryMethodSplitSources
{
    #[Query]
    public int $page;

    #[Body]
    public string $term;
}

it('keeps the query string and the body separate on a QUERY request', function () {
    /** @var TestCase $this */
    $request = Request::create(
        '/?page=3',
        'QUERY',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['term' => 'roma']),
    );
    $this->app->bind('request', fn () => $request);

    $mapped = $this->mapRequest(TestQueryMethodSplitSources::class);

    expect($mapped->page)->toBe(3)
        ->and($mapped->term)->toBe('roma');
});

#[RomaRequest]
readonly class QueryMethodSearchRequest
{
    public function __construct(
        public string $term,
        public Method $method,
    ) {}
}

it('injects a mapped request into a QUERY route', function () {
    /** @var TestCase $this */
    // Laravel's Route::any()/$verbs predate QUERY, so a QUERY route is
    // registered with Route::match().
    Route::match(['QUERY'], '/search', fn (QueryMethodSearchRequest $request) => response()->json([
        'term' => $request->term,
        'method' => $request->method->value,
    ]));

    $this->json('QUERY', '/search', ['term' => 'roma'])
        ->assertOk()
        ->assertJson(['term' => 'roma', 'method' => 'QUERY']);
});

it('fails validation on a QUERY route with a missing body key', function () {
    /** @var TestCase $this */
    Route::match(['QUERY'], '/search', fn (QueryMethodSearchRequest $request) => response()->json(['ran' => true]));

    $this->json('QUERY', '/search', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['input.term']);
});
