<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Message;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Attributes\RulePerItem;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

readonly class NonFiniteLine
{
    public function __construct(
        #[Rule('min:0')]
        public float $price,
    ) {}
}

enum NonFiniteCurrency: string
{
    case Usd = 'usd';
}

readonly class NonFiniteOrder
{
    /**
     * @param  list<float>  $rates
     * @param  list<NonFiniteLine>  $lines
     * @param  array{total: float}  $summary
     */
    public function __construct(
        #[Rule('between:-9999999999.99,9999999999.99')]
        public ?float $amount = null,

        #[RulePerItem('min:0')]
        public array $rates = [],

        public array $lines = [],
        public array $summary = ['total' => 0.0],
        public ?NonFiniteLine $line = null,
    ) {}
}

readonly class NonFiniteScalars
{
    public function __construct(
        #[Rule('max:5')]
        public ?int $count = null,

        #[Rule('max:5')]
        public ?string $code = null,

        public ?bool $active = null,
        public ?NonFiniteCurrency $currency = null,
        public mixed $raw = null,
    ) {}
}

readonly class NonFiniteMessage
{
    public function __construct(
        #[Message(numeric: 'Send a real amount.')]
        public float $amount = 0.0,
    ) {}
}

readonly class NonFiniteRequest
{
    /**
     * @param  list<float>  $rates
     */
    public function __construct(
        #[Rule('max:100')]
        public ?float $amount = null,

        public array $rates = [],

        #[Query]
        public ?float $limit = null,
    ) {}
}

/**
 * @return array<string, list<string>>
 */
function nonFiniteErrors(Closure $map): array
{
    try {
        $map();
    } catch (MappingValidationException|ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a validation exception');
}

dataset('non-finite floats', ['NAN' => NAN, 'INF' => INF, '-INF' => -INF]);

it('rejects a non-finite float for a float property', function (float $value) {
    expect(nonFiniteErrors(fn () => Mapper::fromArray(NonFiniteOrder::class, ['amount' => $value])))
        ->toBe(['data.amount' => ['The data.amount field must be a number.']])
        ->and(nonFiniteErrors(fn () => Mapper::validate(new NonFiniteOrder(amount: $value))))
        ->toBe(['amount' => ['The amount field must be a number.']]);
})->with('non-finite floats');

it('rejects a numeric string too large for a float', function () {
    expect(nonFiniteErrors(fn () => Mapper::fromArray(NonFiniteOrder::class, ['amount' => '1e999'])))
        ->toBe(['data.amount' => ['The data.amount field must be a number.']]);
});

it('rejects a non-finite float in a list and checks the other elements as usual', function () {
    expect(nonFiniteErrors(fn () => Mapper::fromArray(NonFiniteOrder::class, ['rates' => [1.5, NAN, -1, INF]])))->toBe([
        'data.rates.1' => ['The data.rates.1 field must be a number.'],
        'data.rates.2' => ['The data.rates.2 field must be at least 0.'],
        'data.rates.3' => ['The data.rates.3 field must be a number.'],
    ])->and(nonFiniteErrors(fn () => Mapper::validate(new NonFiniteOrder(rates: [1.5, -INF]))))->toBe([
        'rates.1' => ['The rates.1 field must be a number.'],
    ]);
});

it('rejects a non-finite float in a nested object, a list of objects and a shape', function () {
    expect(nonFiniteErrors(fn () => Mapper::fromArray(NonFiniteOrder::class, [
        'line' => ['price' => NAN],
        'lines' => [['price' => 2], ['price' => INF]],
        'summary' => ['total' => NAN],
    ])))->toBe([
        'data.lines.1.price' => ['The data.lines.1.price field must be a number.'],
        'data.summary.total' => ['The data.summary.total field must be a number.'],
        'data.line.price' => ['The data.line.price field must be a number.'],
    ])->and(nonFiniteErrors(fn () => Mapper::validate(new NonFiniteOrder(lines: [new NonFiniteLine(NAN)]))))->toBe([
        'lines.0.price' => ['The lines.0.price field must be a number.'],
    ]);
});

it('rejects a non-finite float for an int, a string, a bool and an enum', function (float $value) {
    expect(nonFiniteErrors(fn () => Mapper::fromArray(NonFiniteScalars::class, [
        'count' => $value,
        'code' => $value,
        'active' => $value,
        'currency' => $value,
    ])))->toBe([
        'data.count' => ['The data.count field must be an integer.'],
        'data.code' => ['The data.code field must be a string.'],
        'data.active' => ['The data.active field must be true or false.'],
        'data.currency' => ['The data.currency field must be one of: usd.'],
    ]);
})->with('non-finite floats');

it('accepts a non-finite float for a mixed property', function () {
    expect(Mapper::fromArray(NonFiniteScalars::class, ['raw' => INF])->raw)->toBe(INF);
});

it('uses the numeric message from #[Message] for a non-finite float', function () {
    expect(nonFiniteErrors(fn () => Mapper::fromArray(NonFiniteMessage::class, ['amount' => NAN])))
        ->toBe(['data.amount' => ['Send a real amount.']]);
});

it('rejects a number that overflows to INF in a JSON body or a query string', function () {
    /** @var TestCase $this */
    $this->app->instance('request', Request::create(
        '/?limit=1e999',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{"amount": 1e999, "rates": [1.5, -1e999]}',
    ));

    expect(nonFiniteErrors(fn () => $this->mapRequest(NonFiniteRequest::class)))->toBe([
        'input.amount' => ['The input.amount field must be a number.'],
        'input.rates.1' => ['The input.rates.1 field must be a number.'],
        'query.limit' => ['The query.limit field must be a number.'],
    ]);
});
