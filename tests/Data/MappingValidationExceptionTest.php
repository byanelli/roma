<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Http\Request\Injection\Request as RomaRequest;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

#[RomaRequest]
readonly class MappingErrorSignup
{
    /**
     * @param  list<int>  $ids
     */
    public function __construct(
        public string $name,
        #[Rule('email')]
        public string $email,
        public array $ids = [],
    ) {}
}

function mapSignupArray(array $data): MappingErrorSignup
{
    return Mapper::fromArray(MappingErrorSignup::class, $data);
}

it('throws MappingValidationException with the errors and class when an array fails validation', function () {
    try {
        mapSignupArray(['email' => 'nope', 'ids' => [1, 'two']]);
    } catch (MappingValidationException $e) {
        expect($e)->not->toBeInstanceOf(ValidationException::class)
            ->and($e->className())->toBe(MappingErrorSignup::class)
            ->and($e->errors())->toBe([
                'data.name' => ['The data.name field is required.'],
                'data.email' => ['The data.email field must be a valid email address.'],
                'data.ids.1' => ['The data.ids.1 field must be an integer.'],
            ])
            ->and($e->getMessage())->toBe(
                'Roma could not map MappingErrorSignup: data.name: The data.name field is required.; '
                .'data.email: The data.email field must be a valid email address.; '
                .'data.ids.1: The data.ids.1 field must be an integer.'
            );

        return;
    }

    $this->fail('Expected a MappingValidationException');
});

it('lists at most three errors in the message', function () {
    $exception = new MappingValidationException(MappingErrorSignup::class, [
        'data.a' => ['A.'],
        'data.b' => ['B.'],
        'data.c' => ['C.'],
        'data.d' => ['D.'],
        'data.e' => ['E.'],
    ]);

    expect($exception->getMessage())
        ->toBe('Roma could not map MappingErrorSignup: data.a: A.; data.b: B.; data.c: C. (and 2 more)');
});

it('still answers an invalid HTTP request with a 422 and the same JSON', function () {
    /** @var TestCase $this */
    Route::post('/mapping-errors', fn (MappingErrorSignup $request) => ['ok' => true]);

    $this->postJson('/mapping-errors', ['email' => 'nope', 'ids' => [1, 'two']])
        ->assertStatus(422)
        ->assertExactJson([
            'message' => 'The input.name field is required. (and 2 more errors)',
            'errors' => [
                'input.name' => ['The input.name field is required.'],
                'input.email' => ['The input.email field must be a valid email address.'],
                'input.ids.1' => ['The input.ids.1 field must be an integer.'],
            ],
        ]);
});

it('renders a MappingValidationException that escapes a controller as a 500', function () {
    /** @var TestCase $this */
    Route::post('/mapping-escapes', fn () => mapSignupArray([]));

    $this->postJson('/mapping-escapes', ['name' => 'Bill'])->assertStatus(500);
});
