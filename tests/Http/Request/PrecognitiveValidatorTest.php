<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Data\ArrayInput;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Http\Request\Precognition\PrecognitiveValidator;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

readonly class PrecognitiveArraySignup
{
    public function __construct(
        public string $name,
        #[Rule('email')]
        public string $email,
    ) {}
}

/**
 * What the validator throws for the rules of an array-mapped class.
 *
 * @param  array<string, mixed>  $data
 */
function precognitiveArrayValidation(array $data, ?string $validateOnly, bool $bareKeys): Throwable
{
    $request = Request::create('/signup', 'POST');

    if ($validateOnly !== null) {
        $request->headers->set('Precognition-Validate-Only', $validateOnly);
    }

    $objectMapper = app(ObjectMapper::class);
    $mapping = $objectMapper->mapping(PrecognitiveArraySignup::class, new ArrayInput($data), new ArrayPolicy);

    try {
        new PrecognitiveValidator($objectMapper)->validate($request, $mapping->validationRules(), $bareKeys);
    } catch (Throwable $e) {
        return $e;
    }
}

it('narrows the rules of an array-mapped class to the Validate-Only fields and keys errors by the bare field', function () {
    /** @var TestCase $this */
    $e = precognitiveArrayValidation(['email' => 'nope'], 'email', bareKeys: true);

    expect($e)->toBeInstanceOf(ValidationException::class)
        ->and($e->errors())->toBe(['email' => ['The email field must be a valid email address.']]);
});

it('keys errors by the source-prefixed key without bare keys', function () {
    /** @var TestCase $this */
    $e = precognitiveArrayValidation(['email' => 'nope'], 'email', bareKeys: false);

    expect($e)->toBeInstanceOf(ValidationException::class)
        ->and($e->errors())->toBe(['data.email' => ['The data.email field must be a valid email address.']]);
});

it('answers passing data with a 204', function () {
    /** @var TestCase $this */
    $e = precognitiveArrayValidation(['email' => 'bill@example.com'], 'email', bareKeys: true);

    expect($e)->toBeInstanceOf(HttpException::class)
        ->and($e->getStatusCode())->toBe(204)
        ->and($e->getHeaders())->toBe(['Precognition-Success' => 'true']);
});

it('validates all fields without Precognition-Validate-Only', function () {
    /** @var TestCase $this */
    $e = precognitiveArrayValidation(['email' => 'bill@example.com'], null, bareKeys: true);

    expect($e)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($e->errors()))->toBe(['name']);
});
