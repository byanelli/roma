<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\DateFormat;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\Serialization\BoolStyle;
use BYanelli\Roma\Serialization\CustomizesSerialization;
use BYanelli\Roma\Serialization\Destination;
use BYanelli\Roma\Serialization\DestinationAttribute;
use BYanelli\Roma\Serialization\EnumStyle;
use BYanelli\Roma\Serialization\IsArrayable;
use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\Serialization\Serialized;
use BYanelli\Roma\Serialization\Serializer as SerializerService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;

enum SerializedColor: string
{
    case Red = 'red';
}

enum SerializedSize
{
    case Large;
}

class SerializedSwatch implements Arrayable
{
    use IsArrayable;

    public function __construct(public SerializedColor $color) {}
}

readonly class SerializedPlainPoint
{
    public function __construct(
        public int $x,
        public SerializedColor $color,
    ) {}
}

class SerializedForeignArrayable implements Arrayable
{
    public function toArray(): array
    {
        return ['color' => SerializedColor::Red];
    }
}

class SerializedPalette extends Response
{
    /**
     * @param  list<SerializedSwatch>  $swatches
     */
    public function __construct(
        public SerializedColor $primary,
        public SerializedSize $size,
        public SerializedSwatch $swatch,
        public array $swatches,
        public SerializedPlainPoint $point,
        public SerializedForeignArrayable $foreign,
    ) {}
}

function palette(): SerializedPalette
{
    return new SerializedPalette(
        SerializedColor::Red,
        SerializedSize::Large,
        new SerializedSwatch(SerializedColor::Red),
        [new SerializedSwatch(SerializedColor::Red)],
        new SerializedPlainPoint(3, SerializedColor::Red),
        new SerializedForeignArrayable,
    );
}

it('applies the profile enum style to the same object', function () {
    $nameAndValue = ['name' => 'Red', 'value' => 'red'];

    expect(Serializer::toArray(palette(), Profile::response())['primary'])->toBe($nameAndValue)
        ->and(Serializer::toArray(palette(), Profile::clientRequest())['primary'])->toBe('red')
        ->and(Serializer::toArray(palette(), Profile::storage())['primary'])->toBe('red')
        ->and(Serializer::toArray(palette(), new Profile(EnumStyle::Name, DATE_ATOM, BoolStyle::Native, [Destination::Body]))['primary'])->toBe('Red')
        ->and(Serializer::toArray(palette(), Profile::clientRequest())['size'])->toBe('Large');
});

it('applies the profile to nested objects, arrays, plain objects and foreign arrayables', function () {
    expect(Serializer::toArray(palette(), Profile::clientRequest()))->toBe([
        'primary' => 'red',
        'size' => 'Large',
        'swatch' => ['color' => 'red'],
        'swatches' => [['color' => 'red']],
        'point' => ['x' => 3, 'color' => 'red'],
        'foreign' => ['color' => 'red'],
    ]);
});

it('serializes a plain nested object without the trait under the response profile', function () {
    expect(palette()->toResponseBody()['point'])->toBe(['x' => 3, 'color' => ['name' => 'Red', 'value' => 'red']]);
});

it('serializes a plain object passed to the facade', function () {
    expect(Serializer::serialize(new SerializedPlainPoint(1, SerializedColor::Red), Profile::storage()))
        ->toEqual(new Serialized(['x' => 1, 'color' => 'red']));
});

class SerializedStatusResponse extends Response
{
    public string $name = 'Bill';

    #[Status]
    public int $status = 201;
}

class SerializedHeaderResponse extends Response
{
    #[Header('X-Color')]
    public SerializedColor $color = SerializedColor::Red;
}

it('throws for a destination the profile does not allow', function () {
    expect(fn () => Serializer::serialize(new SerializedStatusResponse, Profile::clientRequest()))
        ->toThrow(RuntimeException::class, 'SerializedStatusResponse::$status is written to the Status destination')
        ->and(fn () => Serializer::toArray(new SerializedHeaderResponse, Profile::storage()))
        ->toThrow(RuntimeException::class, 'SerializedHeaderResponse::$color is written to the Header destination');
});

it('returns the status and headers separately from the body', function () {
    expect(Serializer::serialize(new SerializedStatusResponse))->toEqual(new Serialized(['name' => 'Bill'], status: 201))
        ->and(Serializer::serialize(new SerializedHeaderResponse))->toEqual(new Serialized([], ['X-Color' => 'red']));
});

#[Attribute(Attribute::TARGET_PROPERTY)]
class SerializedToQuery implements DestinationAttribute
{
    public function destination(): Destination
    {
        return Destination::Query;
    }

    public function destinationKey(): ?string
    {
        return null;
    }
}

class SerializedSearch
{
    /**
     * @param  list<bool>  $flags
     */
    public function __construct(
        #[SerializedToQuery]
        public bool $exact,
        #[SerializedToQuery]
        public array $flags,
        public bool $inBody,
    ) {}
}

it('writes query booleans in the profile style', function () {
    $search = new SerializedSearch(true, [false], true);

    expect(Serializer::serialize($search, Profile::clientRequest()))
        ->toEqual(new Serialized(['inBody' => true], query: ['exact' => 'true', 'flags' => ['false']]));
});

class SerializedRuntimeDates extends Response implements CustomizesSerialization
{
    public function __construct(
        public DateTimeImmutable $runtime,
        #[DateFormat('Y')]
        public DateTimeImmutable $attribute,
        public DateTimeImmutable $profileDefault,
        public bool $short = true,
    ) {}

    public function dateFormat(ReflectionProperty $property, Profile $profile): ?string
    {
        return $property->getName() === 'runtime' && $this->short ? 'Y-m-d' : null;
    }
}

it('takes a date format from CustomizesSerialization before #[DateFormat] and the profile', function () {
    $date = new DateTimeImmutable('2024-01-02T03:04:05+00:00');

    expect(new SerializedRuntimeDates($date, $date, $date)->toArray())->toBe([
        'runtime' => '2024-01-02',
        'attribute' => '2024',
        'profileDefault' => '2024-01-02T03:04:05+00:00',
        'short' => true,
    ]);
});

it('encodes a response as JSON the same way as its response body', function () {
    $response = palette();

    expect(json_encode($response))->toBe(json_encode($response->toResponseBody()))
        ->and(json_encode(['wrapped' => $response]))->toBe(json_encode(['wrapped' => $response->toResponseBody()]));
});

class SerializedHookResponse extends Response
{
    #[Status]
    public int $status = 201;

    #[Header('X-A')]
    public string $a = '1';

    public function responseStatus(Serialized $serialized): int
    {
        return $serialized->status + 1;
    }

    public function responseHeaders(Serialized $serialized): array
    {
        return [...$serialized->headers, 'X-B' => '2'];
    }
}

it('builds the response from the public status and header hooks', function () {
    $response = new SerializedHookResponse()->toResponse(new Request);

    expect($response->getStatusCode())->toBe(202)
        ->and($response->headers->get('X-A'))->toBe('1')
        ->and($response->headers->get('X-B'))->toBe('2');
});

readonly class StoredAddress
{
    public function __construct(
        public string $city,
        public ?string $postcode = null,
    ) {}
}

readonly class StoredOrder
{
    /**
     * @param  list<StoredAddress>  $previous
     * @param  list<SerializedColor>  $colors
     */
    public function __construct(
        public string $id,
        public int $quantity,
        public float $price,
        public bool $gift,
        public SerializedColor $color,
        public SerializedSize $size,
        public CarbonImmutable $placedAt,
        public StoredAddress $address,
        public array $previous,
        public array $colors,
        public ?string $note = null,
    ) {}
}

it('produces storage output that maps back to an equal object', function () {
    $order = new StoredOrder(
        id: 'A-1',
        quantity: 2,
        price: 9.5,
        gift: true,
        color: SerializedColor::Red,
        size: SerializedSize::Large,
        placedAt: CarbonImmutable::parse('2026-10-13T09:30:00+00:00'),
        address: new StoredAddress('Boston', '02101'),
        previous: [new StoredAddress('Albany')],
        colors: [SerializedColor::Red],
    );

    $stored = Serializer::toArray($order, Profile::storage());

    expect(Mapper::fromArray(StoredOrder::class, $stored))
        ->toEqual($order);
});

class SerializedCountingSubject
{
    public int $value = 1;
}

it('builds each class plan once', function () {
    $serializer = new SerializerService;
    $plans = new ReflectionProperty($serializer, 'plans');

    $serializer->toArray(new SerializedCountingSubject);
    $first = $plans->getValue($serializer)[SerializedCountingSubject::class];
    $serializer->toArray(new SerializedCountingSubject);

    expect($plans->getValue($serializer)[SerializedCountingSubject::class])->toBe($first);
});
