<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Guard;
use BYanelli\Roma\Attributes\OptionalOnUpdate;
use BYanelli\Roma\Attributes\RejectUnknownKeys;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Attributes\RulePerItem;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Http\Attributes\Accessors\Ip;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Path;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Request\HandlesFailedValidation;
use BYanelli\Roma\Http\Request\Injection\ContextualBindingException;
use BYanelli\Roma\Http\Request\Injection\RequestBody;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BodyLog
{
    /** @var list<string> */
    public array $calls = [];
}

readonly class BodyMailConfig
{
    /**
     * @param  list<string>  $to_emails
     */
    public function __construct(
        #[RulePerItem('email')]
        public array $to_emails,
    ) {}
}

readonly class BodySignup
{
    public function __construct(
        public string $name,
        #[Rule('email')]
        public string $email,
        public ?BodyMailConfig $config = null,
    ) {}

    #[Guard]
    public function log(BodyLog $log): void
    {
        $log->calls[] = 'guard';
    }
}

#[RejectUnknownKeys]
readonly class StrictBodySignup
{
    public function __construct(
        public string $name,
    ) {}
}

readonly class BodyProfile
{
    public function __construct(
        #[OptionalOnUpdate]
        public string|Missing $name,
        public string $email,
    ) {}
}

readonly class HandledBodySignup implements HandlesFailedValidation
{
    public function __construct(
        #[Rule('email')]
        public string $email,
    ) {}

    public static function failedValidation(ValidationException $exception): SymfonyResponse
    {
        return response()->json(['error' => collect($exception->errors())->flatten()->first()], 400);
    }
}

readonly class BodyWithQuery
{
    public function __construct(
        public string $name,
        #[Query]
        public int $page,
    ) {}
}

readonly class BodyWithHeader
{
    public function __construct(
        #[Header('X-Api-Key')]
        public string $apiKey,
    ) {}
}

readonly class BodyWithPath
{
    public function __construct(
        #[Path]
        public int $id,
    ) {}
}

readonly class BodyWithAccessor
{
    public function __construct(
        #[Ip]
        public string $ip,
    ) {}
}

beforeEach(function () {
    /** @var TestCase $this */
    $this->app->instance(BodyLog::class, new BodyLog);

    Route::post('/body/signup', function (
        #[RequestBody]
        BodySignup $signup,
    ) {
        app(BodyLog::class)->calls[] = 'controller';

        return response()->json($signup);
    })->middleware(HandlePrecognitiveRequests::class);

    Route::post('/body/strict', fn (
        #[RequestBody]
        StrictBodySignup $signup,
    ) => response()->json($signup));

    Route::match(['POST', 'PATCH'], '/body/profile', fn (
        #[RequestBody]
        BodyProfile $profile,
    ) => response()->json(['name' => $profile->name instanceof Missing ? 'missing' : $profile->name]));

    Route::post('/body/handled', fn (
        #[RequestBody]
        HandledBodySignup $signup,
    ) => response()->json($signup));
});

it('maps a JSON body, nested objects included, and runs guards', function () {
    /** @var TestCase $this */
    $this->postJson('/body/signup', ['name' => 'Bill', 'email' => 'bill@example.com', 'config' => ['to_emails' => ['a@example.com']]])
        ->assertOk()
        ->assertExactJson(['name' => 'Bill', 'email' => 'bill@example.com', 'config' => ['to_emails' => ['a@example.com']]]);

    expect($this->app->make(BodyLog::class)->calls)->toBe(['guard', 'controller']);
});

it('ignores the query string', function () {
    /** @var TestCase $this */
    $this->postJson('/body/signup?name=Query', ['email' => 'bill@example.com'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The name field is required.',
            'errors' => ['name' => ['The name field is required.']],
        ]);
});

it('keys errors as Laravel does, nested and array-member keys included', function () {
    /** @var TestCase $this */
    $this->postJson('/body/signup', ['name' => 'Bill', 'email' => 'nope', 'config' => ['to_emails' => ['nope']]])
        ->assertUnprocessable()
        ->assertJsonPath('errors', [
            'email' => ['The email field must be a valid email address.'],
            'config.to_emails.0' => ['The config.to_emails.0 field must be a valid email address.'],
        ]);
});

it('keys errors as Laravel does whatever source_prefixed_errors says', function (bool $prefixed) {
    /** @var TestCase $this */
    config(['roma.source_prefixed_errors' => $prefixed]);

    $this->postJson('/body/signup', ['email' => 'bill@example.com'])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['name' => ['The name field is required.']]);
})->with([true, false]);

it('leaves the _token and _method fields of a form out of the data', function () {
    /** @var TestCase $this */
    $this->post('/body/strict', ['name' => 'Bill', '_token' => 'abc', '_method' => 'POST'])
        ->assertOk()
        ->assertExactJson(['name' => 'Bill']);

    $this->withHeader('Accept', 'application/json')
        ->post('/body/strict', ['name' => 'Bill', 'extra' => 'x'])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['extra' => ['The extra field is prohibited.']]);
});

it('maps a POST as a Create and a PATCH as an Update', function () {
    /** @var TestCase $this */
    $this->postJson('/body/profile', ['email' => 'bill@example.com'])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['name' => ['The name field is required.']]);

    $this->patchJson('/body/profile', ['email' => 'bill@example.com'])
        ->assertOk()
        ->assertExactJson(['name' => 'missing']);
});

it('maps a form PATCH sent as a POST with _method as an Update', function () {
    /** @var TestCase $this */
    $this->post('/body/profile', ['email' => 'bill@example.com', '_method' => 'PATCH'])
        ->assertOk()
        ->assertExactJson(['name' => 'missing']);
});

it('answers a failed validation with the response of HandlesFailedValidation', function () {
    /** @var TestCase $this */
    $this->postJson('/body/handled', ['email' => 'nope'])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'The email field must be a valid email address.']);
});

it('answers a valid precognitive request with a 204 without running guards or the controller', function () {
    /** @var TestCase $this */
    $this->withPrecognition()
        ->postJson('/body/signup', ['name' => 'Bill', 'email' => 'bill@example.com'])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');

    expect($this->app->make(BodyLog::class)->calls)->toBe([]);
});

it('returns bare keys for a failing precognitive request', function () {
    /** @var TestCase $this */
    $response = $this->withPrecognition()->postJson('/body/signup', ['email' => 'nope']);

    $response->assertUnprocessable();

    expect(array_keys($response->json('errors')))->toBe(['name', 'email']);
});

it('validates only the fields in Precognition-Validate-Only', function () {
    /** @var TestCase $this */
    $this->withPrecognition()
        ->withHeader('Precognition-Validate-Only', 'email')
        ->postJson('/body/signup', ['email' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['email' => ['The email field must be a valid email address.']]);

    $this->withPrecognition()
        ->withHeader('Precognition-Validate-Only', 'email')
        ->postJson('/body/signup', ['email' => 'bill@example.com'])
        ->assertNoContent();

    expect($this->app->make(BodyLog::class)->calls)->toBe([]);
});

it('maps the same class from an array', function () {
    expect(Mapper::fromArray(BodySignup::class, ['name' => 'Bill', 'email' => 'bill@example.com']))
        ->toEqual(new BodySignup('Bill', 'bill@example.com'));
});

it('rejects a class that reads other parts of the request when its definition is built', function (string $class, string $message) {
    /** @var TestCase $this */
    $this->setRequest(json: ['name' => 'Bill']);

    expect(fn () => $this->getRequestMapper()->mapBody($class))->toThrow(RuntimeException::class, $message);
})->with([
    [BodyWithQuery::class, 'maps from the "query" source, which ArrayPolicy does not allow'],
    [BodyWithHeader::class, 'maps from the "header" source, which ArrayPolicy does not allow'],
    [BodyWithPath::class, 'maps from the "path" source, which ArrayPolicy does not allow'],
    [BodyWithAccessor::class, 'maps from the "request.ip" source, which ArrayPolicy does not allow'],
]);

it('names #[RequestBody] when the parameter has no class type', function () {
    /** @var TestCase $this */
    expect(fn () => $this->app->call(fn (
        #[RequestBody]
        BodySignup|BodyProfile $body,
    ) => $body))->toThrow(ContextualBindingException::class, 'using the #[RequestBody] attribute: the parameter $body must be type-hinted with a class');
});
