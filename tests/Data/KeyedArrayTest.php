<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

function mapKeyed(string $class, array $data): mixed
{
    return app(ObjectMapper::class)->map($class, new ArrayPayload($data), new ArrayPolicy);
}

/**
 * @return array<string, list<string>>
 */
function keyedErrors(string $class, array $data): array
{
    try {
        mapKeyed($class, $data);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected ValidationException');
}

enum KeyedTier: string
{
    case Free = 'free';
    case Paid = 'paid';
}

readonly class KeyedAddress
{
    public function __construct(
        public string $city,
        public ?string $postcode = null,
    ) {}
}

readonly class KeyedMaps
{
    /**
     * @param  array<string, int>  $counts
     * @param  array<string, KeyedAddress>  $addresses
     * @param  array<int, string>  $names
     * @param  array<string, list<KeyedTier>>  $tiers
     */
    public function __construct(
        public array $counts,
        public array $addresses,
        public array $names,
        public array $tiers = [],
    ) {}
}

it('maps array<K, T> by coercing each value and keeping the keys', function () {
    $maps = mapKeyed(KeyedMaps::class, [
        'counts' => ['a' => '1', 'b' => 2],
        'addresses' => ['home' => ['city' => 'Boston']],
        'names' => [5 => 'five', '9' => 'nine'],
        'tiers' => ['bill' => ['free', 'paid']],
    ]);

    expect($maps->counts)->toBe(['a' => 1, 'b' => 2])
        ->and($maps->addresses)->toEqual(['home' => new KeyedAddress('Boston')])
        ->and($maps->names)->toBe([5 => 'five', 9 => 'nine'])
        ->and($maps->tiers)->toBe(['bill' => [KeyedTier::Free, KeyedTier::Paid]]);
});

it('validates each map value under its key', function () {
    expect(keyedErrors(KeyedMaps::class, [
        'counts' => ['a' => 'many'],
        'addresses' => ['home' => ['postcode' => '02101']],
        'names' => [],
        'tiers' => ['bill' => ['gold']],
    ]))->toHaveKeys([
        'data.counts.a',
        'data.addresses.home.city',
        'data.tiers.bill.0',
    ]);
});

it('rejects non-integer keys for array<int, T>', function () {
    $errors = keyedErrors(KeyedMaps::class, [
        'counts' => ['a' => 1],
        'addresses' => ['home' => ['city' => 'Boston']],
        'names' => ['x' => 'ex'],
    ]);

    expect($errors)->toBe(['data.names' => ['The data.names field must only have integer keys.']]);
});

readonly class KeyedShapes
{
    /**
     * @param  array{input: float, output: float}  $price
     * @param  array{a: int, b?: string, c?: ?bool}  $partial
     * @param  array{home: KeyedAddress, tiers?: list<KeyedTier>}|null  $profile
     */
    public function __construct(
        public array $price,
        public array $partial,
        public ?array $profile = null,
    ) {}
}

it('maps an array shape to a plain array, coercing each key', function () {
    $shapes = mapKeyed(KeyedShapes::class, [
        'price' => ['input' => '1.5', 'output' => 2, 'extra' => 'dropped'],
        'partial' => ['a' => '3', 'c' => null],
        'profile' => ['home' => ['city' => 'Boston'], 'tiers' => ['paid']],
    ]);

    expect($shapes->price)->toBe(['input' => 1.5, 'output' => 2.0])
        ->and($shapes->partial)->toBe(['a' => 3, 'c' => null])
        ->and($shapes->profile)->toEqual(['home' => new KeyedAddress('Boston'), 'tiers' => [KeyedTier::Paid]]);
});

it('requires the non-optional keys of a present shape', function () {
    expect(keyedErrors(KeyedShapes::class, [
        'price' => ['input' => 'cheap'],
        'partial' => ['b' => 4.5],
        'profile' => [],
    ]))->toEqual([
        'data.price.input' => ['The data.price.input field must be a number.'],
        'data.price.output' => ['The data.price.output field is required.'],
        'data.partial.a' => ['The data.partial.a field is required.'],
        'data.partial.b' => ['The data.partial.b field must be a string.'],
        'data.profile.home' => ['The data.profile.home field is required.'],
    ]);
});

it('does not require the keys of an absent nullable shape', function () {
    $shapes = mapKeyed(KeyedShapes::class, [
        'price' => ['input' => 1, 'output' => 2],
        'partial' => ['a' => 1],
    ]);

    expect($shapes->profile)->toBeNull();
});

readonly class KeyedPriceTable
{
    /**
     * @param  array<string, array<string, array{input: float, output: float}>>  $prices
     */
    public function __construct(public array $prices) {}
}

it('maps nested maps of shapes', function () {
    $table = mapKeyed(KeyedPriceTable::class, [
        'prices' => [
            'gemini' => [
                'flash' => ['input' => '0.3', 'output' => '2.5'],
                'pro' => ['input' => 1.25, 'output' => 10],
            ],
        ],
    ]);

    expect($table->prices)->toBe([
        'gemini' => [
            'flash' => ['input' => 0.3, 'output' => 2.5],
            'pro' => ['input' => 1.25, 'output' => 10.0],
        ],
    ]);
});

it('reports errors inside nested maps of shapes under their full keys', function () {
    expect(keyedErrors(KeyedPriceTable::class, [
        'prices' => [
            'gemini' => [
                'flash' => ['input' => 'x'],
                'pro' => 'free',
            ],
        ],
    ]))->toEqual([
        'data.prices.gemini.flash.input' => ['The data.prices.gemini.flash.input field must be a number.'],
        'data.prices.gemini.flash.output' => ['The data.prices.gemini.flash.output field is required.'],
        'data.prices.gemini.pro' => ['The data.prices.gemini.pro field must be an array.'],
    ]);
});

readonly class KeyedHttpRequest
{
    /**
     * @param  array<string, int>  $limits
     * @param  array{min: int, max?: int}  $range
     */
    public function __construct(
        public array $limits,
        public array $range,
    ) {}
}

it('maps maps and shapes from a request', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['limits' => ['daily' => '10'], 'range' => ['min' => '1', 'max' => '5']]);

    $request = $this->mapRequest(KeyedHttpRequest::class);

    expect($request->limits)->toBe(['daily' => 10])
        ->and($request->range)->toBe(['min' => 1, 'max' => 5]);
});

it('reports request errors for maps and shapes under the input prefix', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['limits' => ['daily' => 'lots'], 'range' => ['max' => '5']]);

    expect(fn () => $this->mapRequest(KeyedHttpRequest::class))
        ->toThrow(fn (ValidationException $e) => expect(array_keys($e->errors()))
            ->toEqualCanonicalizing(['input.limits.daily', 'input.range.min']));
});
