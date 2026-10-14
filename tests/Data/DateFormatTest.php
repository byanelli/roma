<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\DateFormat;
use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Facades\Serializer;
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
        #[DateFormat('Y-m-d')] public CarbonImmutable $day,
        #[DateFormat('d/m/Y H:i')] public Carbon $meeting,
        #[DateFormat('Y-m-d')] public DateTimeInterface $since,
        #[DateFormat('Y-m-d')] public array $holidays = [],
        #[DateFormat('Y-m-d')] public ?DateTimeImmutable $until = null,
    ) {}
}

function mapDateFormatted(array $data): TestDateFormatted
{
    return app(ObjectMapper::class)->map(TestDateFormatted::class, new ArrayPayload($data), new ArrayPolicy);
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
