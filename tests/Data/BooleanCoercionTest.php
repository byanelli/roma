<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\ObjectMapper;
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
    return app(ObjectMapper::class)->map(BoolFlag::class, new ArrayPayload($data), new ArrayPolicy);
}

/**
 * @return array<string, list<string>>
 */
function boolErrors(array $data): array
{
    try {
        mapBool($data);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected ValidationException');
}

it('coerces true values', function (mixed $value) {
    expect(mapBool(['flag' => $value, 'flags' => [$value]]))
        ->toEqual(new BoolFlag(true, [true]));
})->with([true, 1, 'true', '1', 'yes', 'on', 'TRUE', 'Yes', 'ON']);

it('coerces false values', function (mixed $value) {
    expect(mapBool(['flag' => $value, 'flags' => [$value]]))
        ->toEqual(new BoolFlag(false, [false]));
})->with([false, 0, 'false', '0', 'no', 'off', 'False', 'NO', 'Off']);

it('rejects other values with the boolean message', function (mixed $value) {
    expect(boolErrors(['flag' => true, 'flags' => [$value]]))
        ->toBe(['data.flags.0' => ['The data.flags.0 field must be true or false.']]);
})->with(['', ' ', 'maybe', 'y', 't', 2, '2', -1, 1.0, [[]]]);

it('reports a blank required boolean as required', function () {
    expect(boolErrors(['flag' => '']))->toBe(['data.flag' => ['The data.flag field is required.']]);
});

readonly class BoolQueryRequest
{
    public function __construct(
        #[Query] public bool $flag = false,
        #[Query] public ?bool $maybe = null,
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
]);

it('rejects an empty query string boolean', function (string $key) {
    /** @var TestCase $this */
    $this->setRequest(query: [$key => '']);

    expect(fn () => $this->mapRequest(BoolQueryRequest::class))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())
            ->toBe(["query.$key" => ["The query.$key field must be true or false."]]));
})->with(['flag', 'maybe']);

it('accepts an omitted or null optional boolean', function () {
    /** @var TestCase $this */
    $this->setRequest(query: []);

    $request = $this->mapRequest(BoolQueryRequest::class);

    expect($request->flag)->toBeFalse()
        ->and($request->maybe)->toBeNull();
});
