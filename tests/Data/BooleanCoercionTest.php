<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

readonly class BoolFlag
{
    /**
     * @param  list<bool>  $flags
     */
    public function __construct(
        public bool $flag,
        public array $flags = [],
    ) {}
}

function mapBool(array $data): BoolFlag
{
    return Mapper::fromArray(BoolFlag::class, $data);
}

/**
 * @return array<string, list<string>>
 */
function boolErrors(array $data): array
{
    try {
        mapBool($data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected MappingValidationException');
}

it('reads true values as Request::boolean() does', function (mixed $value) {
    expect(mapBool(['flag' => $value, 'flags' => [$value]]))
        ->toEqual(new BoolFlag(true, [true]));
})->with([true, 1, 1.0, 'true', '1', 'yes', 'on', 'TRUE', 'Yes', 'ON', ' true ']);

it('reads any other scalar as false', function (mixed $value) {
    expect(mapBool(['flag' => $value, 'flags' => [$value]]))
        ->toEqual(new BoolFlag(false, [false]));
})->with([false, 0, 2, -1, 0.5, 'false', '0', 'no', 'off', 'False', 'NO', 'Y', 'y', 't', 'maybe', '2', ' ', '']);

it('rejects a value that is not a scalar', function (mixed $value) {
    expect(boolErrors(['flag' => true, 'flags' => [$value]]))
        ->toBe(['data.flags.0' => ['The data.flags.0 field must be true or false.']]);
})->with([[[]], [['yes']], null]);

it('rejects a null for a bool that is not nullable', function () {
    expect(boolErrors(['flag' => null]))->toBe(['data.flag' => ['The data.flag field is required.']]);
});

// Laravel's ConvertEmptyStringsToNull makes a blank request value null, which is still required.
it('reads a blank string as false, even for a required boolean', function () {
    expect(mapBool(['flag' => ''])->flag)->toBeFalse();
});

readonly class BoolQueryRequest
{
    public function __construct(
        #[Query]
        public bool $flag = false,
        #[Query]
        public ?bool $maybe = null,
    ) {}
}

it('coerces query string booleans', function (string $query, bool $expected) {
    /** @var TestCase $this */
    $this->setRequest(query: ['flag' => $query]);

    expect($this->mapRequest(BoolQueryRequest::class)->flag)->toBe($expected);
})->with([
    '?flag=1' => ['1', true],
    '?flag=yes' => ['yes', true],
    '?flag=off' => ['off', false],
    '?flag=Y' => ['Y', false],
]);

it('reads an empty query string boolean as false', function (string $key) {
    /** @var TestCase $this */
    $this->setRequest(query: [$key => '']);

    expect($this->mapRequest(BoolQueryRequest::class)->{$key})->toBeFalse();
})->with(['flag', 'maybe']);

it('rejects an array for a query string boolean', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['flag' => ['1']]);

    expect(fn () => $this->mapRequest(BoolQueryRequest::class))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())
            ->toBe(['query.flag' => ['The query.flag field must be true or false.']]));
});

it('accepts an omitted or null optional boolean', function () {
    /** @var TestCase $this */
    $this->setRequest(query: []);

    $request = $this->mapRequest(BoolQueryRequest::class);

    expect($request->flag)->toBeFalse()
        ->and($request->maybe)->toBeNull();
});
