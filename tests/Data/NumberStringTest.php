<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Message;
use BYanelli\Roma\Attributes\RejectNumbers;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

readonly class NumberAddress
{
    public function __construct(public string $zip) {}
}

readonly class NumberStrings
{
    /**
     * @param  list<string>  $codes
     * @param  array{label: string}  $tag
     */
    public function __construct(
        public string $zip,
        #[Rule('max:3')]
        public string $short = 'a',
        public ?string $ratio = null,
        public ?NumberAddress $address = null,
        public array $codes = [],
        public array $tag = ['label' => 'x'],
    ) {}
}

/**
 * @return array<string, list<string>>
 */
function numberErrors(string $class, array $data): array
{
    try {
        Mapper::fromArray($class, $data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected MappingValidationException');
}

it('maps a number to its string form for a string property', function () {
    $object = Mapper::fromArray(NumberStrings::class, [
        'zip' => 35201,
        'ratio' => 1.5,
        'address' => ['zip' => 90210],
        'codes' => [1, 'b', 2.25],
        'tag' => ['label' => 7],
    ]);

    expect($object->zip)->toBe('35201')
        ->and($object->ratio)->toBe('1.5')
        ->and($object->address)->toEqual(new NumberAddress('90210'))
        ->and($object->codes)->toBe(['1', 'b', '2.25'])
        ->and($object->tag)->toBe(['label' => '7']);
});

it('validates the string form of a number', function () {
    expect(numberErrors(NumberStrings::class, ['zip' => 1, 'short' => 12345]))
        ->toBe(['data.short' => ['The data.short field must not be greater than 3 characters.']]);
});

it('still rejects a bool for a string property', function () {
    expect(numberErrors(NumberStrings::class, ['zip' => true, 'codes' => [false]]))->toBe([
        'data.zip' => ['The data.zip field must be a string.'],
        'data.codes.0' => ['The data.codes.0 field must be a string.'],
    ]);
});

it('maps a number in a JSON request body', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['zip' => 35201]);

    expect($this->mapRequest(NumberStrings::class)->zip)->toBe('35201');
});

readonly class NumbersRejected
{
    /**
     * @param  list<string>  $codes
     */
    public function __construct(
        #[RejectNumbers]
        #[Message(string: 'Send the zip as a string.')]
        public string $zip,
        #[RejectNumbers]
        public array $codes = [],
        public string $note = '',
    ) {}
}

#[RejectNumbers]
readonly class NumbersRejectedClass
{
    public function __construct(public string $zip, public int $count = 0) {}
}

readonly class NumbersRejectedChild extends NumbersRejectedClass {}

it('rejects a number with #[RejectNumbers] on the property', function () {
    expect(numberErrors(NumbersRejected::class, ['zip' => 35201, 'codes' => ['a', 2], 'note' => 3]))->toBe([
        'data.zip' => ['Send the zip as a string.'],
        'data.codes.1' => ['The data.codes.1 field must be a string.'],
    ]);
});

it('rejects a number with #[RejectNumbers] on the class or a parent', function (string $class) {
    expect(numberErrors($class, ['zip' => 35201, 'count' => 2]))
        ->toBe(['data.zip' => ['The data.zip field must be a string.']]);
})->with([NumbersRejectedClass::class, NumbersRejectedChild::class]);

it('rejects a number in a request with #[RejectNumbers]', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['zip' => 35201]);

    expect(fn () => $this->mapRequest(NumbersRejected::class))->toThrow(ValidationException::class);
});

readonly class RejectNumbersOnInt
{
    public function __construct(
        #[RejectNumbers]
        public int $count = 0,
    ) {}
}

it('rejects #[RejectNumbers] on a property without a string', function () {
    expect(fn () => Mapper::fromArray(RejectNumbersOnInt::class, []))
        ->toThrow(RuntimeException::class, 'has #[RejectNumbers], which applies only to a string');
});
