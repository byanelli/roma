<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Guard;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Request\HandlesFailedValidation;
use BYanelli\Roma\Http\Request\Injection\Request as RomaRequest;
use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

#[RomaRequest]
readonly class FirstErrorRequest implements HandlesFailedValidation
{
    public function __construct(
        public string $name,
        #[Rule('email')]
        public string $email,
    ) {}

    #[Guard]
    public function notReserved(): void
    {
        if ($this->name === 'root') {
            throw ValidationException::withMessages(['name' => 'That name is reserved.']);
        }
    }

    public static function failedValidation(ValidationException $exception): SymfonyResponse
    {
        return response()->json(['error' => collect($exception->errors())->flatten()->first()], 400);
    }
}

class ErrorResponse extends Response
{
    public function __construct(
        public string $error,
        #[Status]
        public int $status,
    ) {}
}

#[RomaRequest]
readonly class TypedErrorRequest implements HandlesFailedValidation
{
    public function __construct(public int $count) {}

    public static function failedValidation(ValidationException $exception): ErrorResponse
    {
        return new ErrorResponse('Bad count', 409);
    }
}

#[RomaRequest]
readonly class ThrowingErrorRequest implements HandlesFailedValidation
{
    public function __construct(public int $count) {}

    public static function failedValidation(ValidationException $exception): never
    {
        throw new DomainException('Rejected');
    }
}

beforeEach(function () {
    Route::post('/first-error', fn (FirstErrorRequest $request) => ['name' => $request->name])
        ->middleware(HandlePrecognitiveRequests::class);
    Route::post('/typed-error', fn (TypedErrorRequest $request) => ['count' => $request->count]);
    Route::post('/throwing-error', fn (ThrowingErrorRequest $request) => ['count' => $request->count]);
});

it('sends the response from failedValidation', function () {
    /** @var TestCase $this */
    $this->postJson('/first-error', ['email' => 'nope'])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'The input.name field is required.']);
});

it('passes a guard\'s ValidationException to failedValidation', function () {
    /** @var TestCase $this */
    $this->postJson('/first-error', ['name' => 'root', 'email' => 'root@example.com'])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'That name is reserved.']);
});

it('passes a precognitive request\'s errors to failedValidation', function () {
    /** @var TestCase $this */
    $this->withPrecognition()
        ->withHeader('Precognition-Validate-Only', 'email')
        ->postJson('/first-error', ['email' => 'nope'])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'The email field must be a valid email address.']);
});

it('leaves a valid request alone', function () {
    /** @var TestCase $this */
    $this->postJson('/first-error', ['name' => 'Ada', 'email' => 'ada@example.com'])
        ->assertOk()
        ->assertExactJson(['name' => 'Ada']);
});

it('converts a Responsable from failedValidation', function () {
    /** @var TestCase $this */
    $this->postJson('/typed-error', ['count' => 'many'])
        ->assertStatus(409)
        ->assertExactJson(['error' => 'Bad count']);
});

it('lets failedValidation throw an exception of its own', function () {
    /** @var TestCase $this */
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/throwing-error', ['count' => 'many']))
        ->toThrow(DomainException::class, 'Rejected');
});
