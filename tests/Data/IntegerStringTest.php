<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

enum IntegerStringLevel: int
{
    case None = 0;
    case Low = 1;
    case High = 1000;
}

readonly class IntegerStrings
{
    /**
     * @param  list<int>  $ids
     */
    public function __construct(
        public int $count = 0,
        public array $ids = [],
        public ?IntegerStringLevel $level = null,
    ) {}
}

readonly class IntegerStringQuery
{
    public function __construct(
        #[Query]
        public int $page = 1,
    ) {}
}

/**
 * @return array<string, list<string>>
 */
function integerStringErrors(array $data): array
{
    try {
        Mapper::fromArray(IntegerStrings::class, $data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected MappingValidationException');
}

it('maps an integer string', function (string $value, int $expected) {
    expect(Mapper::fromArray(IntegerStrings::class, ['count' => $value, 'ids' => [$value]]))
        ->toEqual(new IntegerStrings($expected, [$expected]));
})->with([
    ['12', 12],
    ['-3', -3],
    ['+4', 4],
    ['0', 0],
    [' 12 ', 12],
    ['9223372036854775807', PHP_INT_MAX],
]);

// intval() maps these to a different int, such as 1000 for "1e3" and 0 for "1e999".
it('rejects a numeric string that is not an integer', function (string $value) {
    expect(integerStringErrors(['count' => $value, 'ids' => [1, $value]]))->toBe([
        'data.count' => ['The data.count field must be an integer.'],
        'data.ids.1' => ['The data.ids.1 field must be an integer.'],
    ]);
})->with(['1e3', '1E3', '1e999', '1.5', '1.0', '007', '9223372036854775808', '9999999999999999999999']);

it('maps an integer string to an int-backed enum', function (string $value, IntegerStringLevel $expected) {
    expect(Mapper::fromArray(IntegerStrings::class, ['level' => $value])->level)->toBe($expected);
})->with([
    ['1', IntegerStringLevel::Low],
    ['0', IntegerStringLevel::None],
    [' 1000 ', IntegerStringLevel::High],
]);

// intval() maps "1.9" to Low, "1e3" to High and "abc" to None.
it('rejects a value that is not an integer for an int-backed enum', function (string|float $value) {
    expect(integerStringErrors(['level' => $value]))
        ->toBe(['data.level' => ['The data.level field must be one of: 0, 1, 1000.']]);
})->with(['1.9', '1e3', '1.0', '01', 'abc', 1.0]);

it('rejects a query parameter that is not an integer', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['page' => '1e3']);

    try {
        $this->mapRequest(IntegerStringQuery::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['query.page' => ['The query.page field must be an integer.']]);
    }
});
