<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\RejectUnknownKeys;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Body;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Request\Injection\Request as RomaRequest;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;

#[RejectUnknownKeys]
readonly class StrictRequestConfig
{
    public function __construct(public bool $enabled = false) {}
}

#[RomaRequest]
#[RejectUnknownKeys]
readonly class StrictRequest
{
    public function __construct(
        public string $name,
        public ?StrictRequestConfig $config = null,
        #[Query]
        public int $page = 1,
    ) {}
}

#[RomaRequest]
#[RejectUnknownKeys]
readonly class StrictBodyRequest
{
    public function __construct(
        public string $name,
        #[Body]
        public string $token = '',
    ) {}
}

#[RomaRequest]
readonly class LooseRequest
{
    public function __construct(
        public string $name,
        public ?StrictRequestConfig $config = null,
    ) {}
}

beforeEach(function () {
    Route::match(['GET', 'POST', 'PUT'], '/strict', fn (StrictRequest $request) => Serializer::toArray($request))
        ->middleware(HandlePrecognitiveRequests::class);
    Route::post('/strict-body', fn (StrictBodyRequest $request) => Serializer::toArray($request));
    Route::post('/loose', fn (LooseRequest $request) => Serializer::toArray($request));
});

it('rejects unknown keys of a JSON body', function () {
    /** @var TestCase $this */
    $this->postJson('/strict', ['name' => 'a', 'k' => 'v', 'config' => ['enabled' => true, 'x' => 1]])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The input.config.x field is prohibited. (and 1 more error)',
            'errors' => [
                'input.config.x' => ['The input.config.x field is prohibited.'],
                'input.k' => ['The input.k field is prohibited.'],
            ],
        ]);
});

it('does not check query string keys', function () {
    /** @var TestCase $this */
    $this->postJson('/strict?utm_source=mail&page=2', ['name' => 'a'])
        ->assertOk()
        ->assertExactJson(['name' => 'a', 'config' => null, 'page' => 2]);

    $this->getJson('/strict?name=a&utm_source=mail')->assertOk();
});

it('does not check the _method and _token fields of a form', function () {
    /** @var TestCase $this */
    $this->post('/strict', ['_method' => 'PUT', '_token' => 'x', 'name' => 'a'])
        ->assertOk()
        ->assertJson(['name' => 'a']);
});

it('rejects unknown keys of a form body', function () {
    /** @var TestCase $this */
    $this->post('/strict', ['name' => 'a', 'k' => 'v'], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['input.k' => 'The input.k field is prohibited.']);
});

it('counts the keys of input and body properties as known to both', function () {
    /** @var TestCase $this */
    $this->postJson('/strict-body', ['name' => 'a', 'token' => 't'])->assertOk();

    $this->postJson('/strict-body', ['name' => 'a', 'k' => 'v'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The input.k field is prohibited. (and 1 more error)',
            'errors' => [
                'input.k' => ['The input.k field is prohibited.'],
                'body.k' => ['The body.k field is prohibited.'],
            ],
        ]);
});

it('rejects unknown keys of a nested class in a request class without the attribute', function () {
    /** @var TestCase $this */
    $this->postJson('/loose', ['name' => 'a', 'k' => 'v', 'config' => ['x' => 1]])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The input.config.x field is prohibited.',
            'errors' => ['input.config.x' => ['The input.config.x field is prohibited.']],
        ]);
});

it('keys and words the errors as Laravel does without source prefixes', function () {
    /** @var TestCase $this */
    config()->set('roma.source_prefixed_errors', false);

    $this->postJson('/strict', ['name' => 'a', 'unknown_key' => 'v'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The unknown key field is prohibited.',
            'errors' => ['unknown_key' => ['The unknown key field is prohibited.']],
        ]);
});

it('checks only the listed fields of a precognitive request', function () {
    /** @var TestCase $this */
    $this->withPrecognition()
        ->withHeader('Precognition-Validate-Only', 'name')
        ->postJson('/strict', ['name' => 'a', 'k' => 'v'])
        ->assertNoContent();

    $this->flushHeaders()
        ->withPrecognition()
        ->postJson('/strict', ['name' => 'a', 'k' => 'v'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['k' => 'The k field is prohibited.']);
});
