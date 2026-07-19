<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Guard;
use BYanelli\Roma\Request\Attributes\Header;
use BYanelli\Roma\Request\Attributes\Rule;
use BYanelli\Roma\Request\ContextualBinding\Request as RomaRequest;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;

class PrecognitionLog
{
    /** @var list<string> */
    public array $calls = [];
}

#[RomaRequest]
readonly class PrecognitiveSignupRequest
{
    public function __construct(
        public string $name,
        #[Rule('email')]
        public string $email,
    ) {}

    #[Guard]
    public function authorize(PrecognitionLog $log): void
    {
        $log->calls[] = 'guard';
    }
}

#[RomaRequest]
readonly class PrecognitiveCodesRequest
{
    /** @var array<string> */
    public array $codes;
}

#[RomaRequest]
readonly class PrecognitiveHeaderRequest
{
    public function __construct(
        public string $name,
        #[Header('X-Api-Key'), Rule('size:8')]
        public string $apiKey,
    ) {}
}

beforeEach(function () {
    /** @var TestCase $this */
    $this->app->instance(PrecognitionLog::class, new PrecognitionLog);
});

function signupRoute(): void
{
    Route::post('/signup', function (PrecognitiveSignupRequest $request) {
        app(PrecognitionLog::class)->calls[] = 'controller';

        return response()->json(['ran' => true]);
    })->middleware(HandlePrecognitiveRequests::class);
}

it('leaves non-precognitive requests untouched', function () {
    /** @var TestCase $this */
    signupRoute();

    $this->postJson('/signup', ['name' => 'Bill', 'email' => 'bill@example.com'])
        ->assertOk()
        ->assertJson(['ran' => true]);

    expect($this->app->make(PrecognitionLog::class)->calls)->toBe(['guard', 'controller']);
});

it('ignores Precognition-Validate-Only on a non-precognitive request', function () {
    /** @var TestCase $this */
    signupRoute();

    $response = $this
        ->withHeader('Precognition-Validate-Only', 'email')
        ->postJson('/signup', ['email' => 'bill@example.com']);

    $response->assertUnprocessable();

    // Everything validates, and errors keep their source-prefixed keys —
    // bare keys are a precognitive-response concern only.
    $this->assertArrayHasKey('input.name', $response->json('errors'));
});

it('answers a valid precognitive request with an empty 204 without running guards or the controller', function () {
    /** @var TestCase $this */
    signupRoute();

    $this->withPrecognition()
        ->postJson('/signup', ['name' => 'Bill', 'email' => 'bill@example.com'])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');

    // Precognition only ever asks "would this form data pass validation?" —
    // the request object is never built, so neither guards nor the controller
    // can run.
    expect($this->app->make(PrecognitionLog::class)->calls)->toBe([]);
});

it('returns validation errors for a failing precognitive request', function () {
    /** @var TestCase $this */
    signupRoute();

    $response = $this->withPrecognition()->postJson('/signup', ['email' => 'nope']);

    $response->assertUnprocessable();

    $errors = $response->json('errors');

    $this->assertArrayHasKey('name', $errors);
    $this->assertArrayHasKey('email', $errors);
});

it('validates only the fields named by Precognition-Validate-Only', function () {
    /** @var TestCase $this */
    signupRoute();

    $response = $this->withPrecognition()
        ->withHeader('Precognition-Validate-Only', 'email')
        ->postJson('/signup', ['email' => 'nope']);

    $response->assertUnprocessable();

    $errors = $response->json('errors');

    $this->assertArrayHasKey('email', $errors);
    $this->assertArrayNotHasKey('name', $errors);
});

it('answers a passing validate-only request with a 204 even though other required fields are absent', function () {
    /** @var TestCase $this */
    signupRoute();

    // "name" is required but absent: the request object could never be
    // constructed. The validate-only path must succeed without trying.
    $this->withPrecognition()
        ->withHeader('Precognition-Validate-Only', 'email')
        ->postJson('/signup', ['email' => 'bill@example.com'])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');

    expect($this->app->make(PrecognitionLog::class)->calls)->toBe([]);
});

it('matches Validate-Only patterns given as source-prefixed keys', function () {
    /** @var TestCase $this */
    signupRoute();

    $response = $this->withPrecognition()
        ->withHeader('Precognition-Validate-Only', 'input.email')
        ->postJson('/signup', ['email' => 'nope']);

    $response->assertUnprocessable();

    $errors = $response->json('errors');

    $this->assertArrayHasKey('email', $errors);
    $this->assertArrayNotHasKey('name', $errors);
});

it('matches Validate-Only patterns against expanded array rules', function () {
    /** @var TestCase $this */
    Route::post('/codes', fn (PrecognitiveCodesRequest $request) => response()->json(['ran' => true]))
        ->middleware(HandlePrecognitiveRequests::class);

    $response = $this->withPrecognition()
        ->withHeader('Precognition-Validate-Only', 'codes.1')
        ->postJson('/codes', ['codes' => ['abc', 123]]);

    $response->assertUnprocessable();

    $this->assertArrayHasKey('codes.1', $response->json('errors'));

    $this->withPrecognition()
        ->withHeader('Precognition-Validate-Only', 'codes.0')
        ->postJson('/codes', ['codes' => ['abc', 123]])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');
});

it('does not validate headers during a precognitive request', function () {
    /** @var TestCase $this */
    Route::post('/with-header', fn (PrecognitiveHeaderRequest $request) => response()->json(['ran' => true]))
        ->middleware(HandlePrecognitiveRequests::class);

    // Precognition concerns form data only: the required X-Api-Key header is
    // absent, yet the precognitive request succeeds on its form data alone.
    $this->withPrecognition()
        ->postJson('/with-header', ['name' => 'Bill'])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');

    // The real submission still enforces it, under its prefixed key.
    $this->flushHeaders();

    $response = $this->postJson('/with-header', ['name' => 'Bill']);

    $response->assertUnprocessable();

    $this->assertArrayHasKey('header.X-Api-Key', $response->json('errors'));
});

it('drops every rule when no Validate-Only pattern matches', function () {
    /** @var TestCase $this */
    signupRoute();

    // Nothing matches "nickname", so nothing is validated — even though the
    // posted email is invalid and name is missing entirely.
    $this->withPrecognition()
        ->withHeader('Precognition-Validate-Only', 'nickname')
        ->postJson('/signup', ['email' => 'nope'])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');
});

it('keys precognitive errors by source-prefixed names when configured to', function () {
    /** @var TestCase $this */
    config()->set('roma.precognition.source_prefixed_errors', true);

    signupRoute();

    $response = $this->withPrecognition()
        ->withHeader('Precognition-Validate-Only', 'email')
        ->postJson('/signup', ['email' => 'nope']);

    $response->assertUnprocessable();

    $this->assertArrayHasKey('input.email', $response->json('errors'));
});
