<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\DateFormat;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Serialization\IsArrayable;
use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\Tests\TestCase;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class TestDateFormatted
{
    /**
     * @param  list<DateTimeImmutable>  $holidays
     */
    public function __construct(
        #[DateFormat('Y-m-d')]
        public CarbonImmutable $day,
        #[DateFormat('d/m/Y H:i')]
        public Carbon $meeting,
        #[DateFormat('Y-m-d')]
        public DateTimeInterface $since,
        #[DateFormat('Y-m-d')]
        public array $holidays = [],
        #[DateFormat('Y-m-d')]
        public ?DateTimeImmutable $until = null,
    ) {}
}

function mapDateFormatted(array $data): TestDateFormatted
{
    return Mapper::fromArray(TestDateFormatted::class, $data);
}

/**
 * @return array<string, list<string>>
 */
function dateFormatErrors(array $data): array
{
    try {
        mapDateFormatted($data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a MappingValidationException');
}

it('parses a date in its #[DateFormat] into the declared class', function () {
    $mapped = mapDateFormatted([
        'day' => '2024-03-05',
        'meeting' => '05/03/2024 14:30',
        'since' => '2020-01-31',
        'holidays' => ['2024-12-25', '2024-12-26'],
        'until' => '2024-06-30',
    ]);

    expect($mapped->day)->toBeInstanceOf(CarbonImmutable::class)
        ->and($mapped->day->format('Y-m-d H:i:s'))->toBe('2024-03-05 00:00:00')
        ->and($mapped->meeting)->toBeInstanceOf(Carbon::class)
        ->and($mapped->meeting->format('Y-m-d H:i'))->toBe('2024-03-05 14:30')
        ->and($mapped->since->format('Y-m-d'))->toBe('2020-01-31')
        ->and($mapped->holidays)->toHaveCount(2)
        ->and($mapped->holidays[0])->toBeInstanceOf(DateTimeImmutable::class)
        ->and($mapped->holidays[1]->format('Y-m-d'))->toBe('2024-12-26')
        ->and($mapped->until)->toBeInstanceOf(DateTimeImmutable::class);
});

it('keeps a null formatted date', function () {
    $mapped = mapDateFormatted(['day' => '2024-03-05', 'meeting' => '05/03/2024 14:30', 'since' => '2020-01-31', 'until' => null]);

    expect($mapped->until)->toBeNull();
});

it('rejects a date that does not match its #[DateFormat] with the date_format message', function () {
    $errors = dateFormatErrors([
        'day' => '03/05/2024',
        'meeting' => '2024-03-05 14:30',
        'since' => '2024-02-30',
        'holidays' => ['2024-12-25', 'Christmas'],
    ]);

    expect($errors)->toBe([
        'data.day' => ['The data.day field must match the format Y-m-d.'],
        'data.meeting' => ['The data.meeting field must match the format d/m/Y H:i.'],
        'data.since' => ['The data.since field must match the format Y-m-d.'],
        'data.holidays.1' => ['The data.holidays.1 field must match the format Y-m-d.'],
    ]);
});

it('serializes a mapped formatted date back to the same string', function () {
    $data = [
        'day' => '2024-03-05',
        'meeting' => '05/03/2024 14:30',
        'since' => '2020-01-31',
        'holidays' => ['2024-12-25'],
        'until' => '2024-06-30',
    ];

    expect(Serializer::toArray(mapDateFormatted($data)))->toBe($data);
});

readonly class TestDateFormatRequest
{
    #[DateFormat('Y-m-d')]
    public DateTimeImmutable $day;
}

it('applies #[DateFormat] to a request property', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['day' => '2024-03-05T10:00:00']);

    try {
        $this->mapRequest(TestDateFormatRequest::class);
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['input.day' => ['The input.day field must match the format Y-m-d.']]);

        return;
    }

    $this->fail('Exception was not thrown');
});

class TestDatedEvent
{
    public function __construct(
        public string $name,
        public CarbonImmutable $at,
        #[DateFormat('H:i')]
        public ?CarbonImmutable $ends = null,
    ) {}
}

class TestDatedEventWithOwnToArray
{
    use IsArrayable;

    public function __construct(public CarbonImmutable $at) {}

    public function toArray(?Profile $profile = null): array
    {
        return ['at' => $this->at];
    }
}

class TestDateCollections
{
    /**
     * @param  list<CarbonImmutable>  $days
     * @param  array<string, CarbonImmutable>  $deadlines
     * @param  array{day: CarbonImmutable, event: TestDatedEvent}  $slot
     */
    public function __construct(
        #[DateFormat('Y-m-d')]
        public array $days,
        #[DateFormat('Y-m-d')]
        public array $deadlines,
        #[DateFormat('Y-m-d')]
        public array $slot,
    ) {}
}

it('applies #[DateFormat] to array elements and map values in both directions', function () {
    $data = [
        'days' => ['2024-03-05', '2024-03-06'],
        'deadlines' => ['draft' => '2024-04-01'],
        'slot' => ['day' => '2024-05-01', 'event' => ['name' => 'Launch', 'at' => '2024-05-01T09:00:00+00:00', 'ends' => '17:00']],
    ];

    $mapped = Mapper::fromArray(TestDateCollections::class, $data);

    expect($mapped->days[1]->toDateTimeString())->toBe('2024-03-06 00:00:00')
        ->and($mapped->deadlines['draft']->toDateTimeString())->toBe('2024-04-01 00:00:00')
        ->and($mapped->slot['event']->at->toDateTimeString())->toBe('2024-05-01 09:00:00')
        ->and(Serializer::toArray($mapped))->toBe($data);
});

it('validates a nested object\'s dates with its own formats', function () {
    $map = fn () => Mapper::fromArray(TestDateCollections::class, [
        'days' => ['2024-03-05'],
        'deadlines' => ['draft' => '2024-04-01'],
        'slot' => ['day' => '2024-05-01', 'event' => ['name' => 'Launch', 'at' => '2024-05-01T09:00:00+00:00', 'ends' => '2024-05-01']],
    ]);

    expect($map)->toThrow(fn (MappingValidationException $e) => expect($e->errors())->toBe([
        'data.slot.event.ends' => ['The data.slot.event.ends field must match the format H:i.'],
    ]));
});

class TestDateFormatOverNested
{
    /**
     * @param  array{day: DateTimeImmutable, event: object}  $slot
     */
    public function __construct(
        #[DateFormat('Y-m-d')]
        public array $slot,
        public ?object $event = null,
    ) {}
}

it('does not apply #[DateFormat] to dates inside a nested object when serializing', function (object $event, array $expected) {
    $at = new CarbonImmutable('2024-05-01 09:00:00', 'UTC');
    $object = new TestDateFormatOverNested(['day' => $at, 'event' => $event], $event);

    expect(Serializer::toArray($object))->toBe([
        'slot' => ['day' => '2024-05-01', 'event' => $expected],
        'event' => $expected,
    ]);
})->with([
    'plain object' => [
        fn () => new TestDatedEvent('Launch', new CarbonImmutable('2024-05-01 09:00:00', 'UTC')),
        ['name' => 'Launch', 'at' => '2024-05-01T09:00:00+00:00', 'ends' => null],
    ],
    'own toArray()' => [
        fn () => new TestDatedEventWithOwnToArray(new CarbonImmutable('2024-05-01 09:00:00', 'UTC')),
        ['at' => '2024-05-01T09:00:00+00:00'],
    ],
    'stdClass' => [
        fn () => (object) ['at' => new CarbonImmutable('2024-05-01 09:00:00', 'UTC')],
        ['at' => '2024-05-01T09:00:00+00:00'],
    ],
]);

class TestDateFormatOnString
{
    public function __construct(
        #[DateFormat('Y-m-d')]
        public string $day = '',
    ) {}
}

class TestDateFormatOnObject
{
    public function __construct(
        #[DateFormat('Y-m-d')]
        public ?TestDatedEvent $event = null,
    ) {}
}

class TestDateFormatOnObjectList
{
    /**
     * @param  list<TestDatedEvent>  $events
     */
    public function __construct(
        #[DateFormat('Y-m-d')]
        public array $events = [],
    ) {}
}

class TestDateFormatOnMixed
{
    public function __construct(
        #[DateFormat('Y-m-d')]
        public mixed $at = null,
    ) {}
}

class TestDateFormatOnUntyped
{
    #[DateFormat('Y-m-d')]
    public $at;
}

class TestDateFormatOnUndocumentedArray
{
    #[DateFormat('Y-m-d')]
    public array $at = [];
}

class TestDateFormatOnMixedMap
{
    /**
     * @var array<string, mixed>
     */
    #[DateFormat('Y-m-d')]
    public array $at = [];
}

it('rejects #[DateFormat] on a type with no date of its own', function (string $class) {
    $message = "Roma property \"{$class}::\$";

    expect(fn () => Mapper::fromArray($class, []))
        ->toThrow(RuntimeException::class, 'has #[DateFormat], but its type is not a date or an array, map or shape of dates')
        ->and(fn () => Serializer::toArray(new $class))
        ->toThrow(RuntimeException::class, $message);
})->with([
    TestDateFormatOnString::class,
    TestDateFormatOnObject::class,
    TestDateFormatOnObjectList::class,
    TestDateFormatOnMixed::class,
    TestDateFormatOnUntyped::class,
    TestDateFormatOnMixedMap::class,
]);

it('rejects #[DateFormat] on an array without a PHPDoc type when serializing', function () {
    expect(fn () => Serializer::toArray(new TestDateFormatOnUndocumentedArray))
        ->toThrow(RuntimeException::class, 'TestDateFormatOnUndocumentedArray::$at" has #[DateFormat], but its type is not a date');
});

class TestDateFormatOnDateUnion
{
    public function __construct(
        #[DateFormat('Y-m-d')]
        public DateTimeImmutable|string $at,
    ) {}
}

it('applies #[DateFormat] to a union with a date', function () {
    $mapped = Mapper::fromArray(TestDateFormatOnDateUnion::class, ['at' => 'soon']);

    expect($mapped->at)->toBe('soon')
        ->and(Serializer::toArray(new TestDateFormatOnDateUnion(new DateTimeImmutable('2024-05-01 09:00:00'))))->toBe(['at' => '2024-05-01']);
});
