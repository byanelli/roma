<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Response\Attributes\DateFormat;
use BYanelli\Roma\Response\Attributes\Header;
use BYanelli\Roma\Response\Attributes\Optional;
use BYanelli\Roma\Response\Attributes\Status;
use BYanelli\Roma\Response\IsArrayable;
use BYanelli\Roma\Response\Response;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// A nested value that is Arrayable via the trait but not a full Response.
class TestAddressResponse implements Arrayable
{
    use IsArrayable;

    public function __construct(
        public string $city,
        public string $zip,
    ) {}
}

// The ergonomic pattern: extend the base Response class.
class TestUserResponse extends Response
{
    public function __construct(
        public string $name,
        public int $age,
        public TestAddressResponse $address,
    ) {}
}

it('serializes public properties to an array, recursing nested arrayables', function () {
    $response = new TestUserResponse('Bill', 40, new TestAddressResponse('NYC', '10001'));

    expect($response->toArray())->toBe([
        'name' => 'Bill',
        'age' => 40,
        'address' => ['city' => 'NYC', 'zip' => '10001'],
    ]);
});

it('converts to a JSON response', function () {
    $response = new TestUserResponse('Bill', 40, new TestAddressResponse('NYC', '10001'));

    $httpResponse = $response->toResponse(new Request);

    expect($httpResponse)->toBeInstanceOf(JsonResponse::class)
        ->and($httpResponse->getData(true))->toBe([
            'name' => 'Bill',
            'age' => 40,
            'address' => ['city' => 'NYC', 'zip' => '10001'],
        ]);
});

enum TestResponseStatus: string
{
    case Active = 'active';
}

enum TestResponseRank
{
    case Gold;
}

class TestValueResponse extends Response
{
    public function __construct(
        public TestResponseStatus $status,
        public TestResponseRank $rank,
        public DateTimeImmutable $createdAt,
    ) {}
}

it('converts enums and dates to their JSON form', function () {
    $response = new TestValueResponse(
        TestResponseStatus::Active,
        TestResponseRank::Gold,
        new DateTimeImmutable('2024-01-02T03:04:05+00:00'),
    );

    expect($response->toArray())->toBe([
        'status' => 'active',                 // backed enum -> value
        'rank' => 'Gold',                     // unit enum -> name
        'createdAt' => '2024-01-02T03:04:05+00:00',
    ]);
});

class TestListResponse extends Response
{
    /**
     * @param  array<TestAddressResponse>  $addresses
     * @param  array<TestResponseStatus>  $statuses
     */
    public function __construct(
        public array $addresses,
        public array $statuses,
    ) {}
}

it('recurses through arrays of response objects and enums', function () {
    $response = new TestListResponse(
        [new TestAddressResponse('NYC', '10001'), new TestAddressResponse('LA', '90001')],
        [TestResponseStatus::Active, TestResponseStatus::Active],
    );

    expect($response->toArray())->toBe([
        'addresses' => [
            ['city' => 'NYC', 'zip' => '10001'],
            ['city' => 'LA', 'zip' => '90001'],
        ],
        'statuses' => ['active', 'active'],
    ]);
});

class TestOptionalResponse extends Response
{
    public string $name;

    #[Optional]
    public ?string $nickname;

    #[Optional]
    public string $phone;
}

it('omits unset #[Optional] properties (nullable or not)', function () {
    $response = new TestOptionalResponse;
    $response->name = 'Bill';
    // nickname and phone left unset

    expect($response->toArray())->toBe(['name' => 'Bill']);
});

it('includes an #[Optional] property once it is set', function () {
    $response = new TestOptionalResponse;
    $response->name = 'Bill';
    $response->nickname = 'B';
    $response->phone = '555';

    expect($response->toArray())->toBe([
        'name' => 'Bill',
        'nickname' => 'B',
        'phone' => '555',
    ]);
});

class TestRequiredResponse extends Response
{
    // Nullable but NOT optional: an implicit default is not assumed.
    public ?string $email;
}

it('throws an actionable error for an unset non-optional property even when nullable', function () {
    $response = new TestRequiredResponse;

    expect(fn () => $response->toArray())
        ->toThrow(RuntimeException::class, 'was never set. Mark it #[Optional]');
});

class TestDefaultResponse extends Response
{
    public ?string $note = null;
}

it('serializes an explicit default', function () {
    $response = new TestDefaultResponse;

    expect($response->toArray())->toBe(['note' => null]);
});

// --- #[Status] ---

class TestStatusResponse extends Response
{
    public string $name = 'Bill';

    #[Status]
    public int $status = 201;
}

class TestDynamicStatusResponse extends Response
{
    public string $name = 'Bill';

    protected function responseStatus(): int
    {
        return 418;
    }
}

it('sets the HTTP status from the #[Status] property and drops it from the body', function () {
    $response = new TestStatusResponse;

    $httpResponse = $response->toResponse(new Request);

    expect($httpResponse->getStatusCode())->toBe(201)
        ->and($httpResponse->getData(true))->toBe(['name' => 'Bill']);
});

it('defaults the HTTP status to 200 without a #[Status] property', function () {
    $response = new TestDefaultResponse;

    expect($response->toResponse(new Request)->getStatusCode())->toBe(200);
});

it('allows overriding responseStatus() for a dynamic code', function () {
    $response = new TestDynamicStatusResponse;

    expect($response->toResponse(new Request)->getStatusCode())->toBe(418);
});

// --- #[Header] ---

class TestHeaderResponse extends Response
{
    public string $name = 'Bill';

    #[Header('X-A')]
    public string $a = '1';

    #[Header('X-B')]
    public string $b = '2';
}

it('lifts #[Header] properties into response headers and drops them from the body', function () {
    $response = new TestHeaderResponse;

    $httpResponse = $response->toResponse(new Request);

    expect($httpResponse->headers->get('X-A'))->toBe('1')
        ->and($httpResponse->headers->get('X-B'))->toBe('2')
        ->and($httpResponse->getData(true))->toBe(['name' => 'Bill']);
});

it('adds no extra headers without a #[Header] property', function () {
    $response = new TestDefaultResponse;

    expect($response->toResponse(new Request)->headers->get('X-A'))->toBeNull();
});

// --- #[DateFormat] ---

class TestDateFormatResponse extends Response
{
    #[DateFormat('Y-m-d')]
    public DateTimeImmutable $createdAt;

    public DateTimeImmutable $updatedAt;
}

it('formats a date property with its #[DateFormat] and leaves others ATOM', function () {
    $response = new TestDateFormatResponse;
    $response->createdAt = new DateTimeImmutable('2024-01-02T03:04:05+00:00');
    $response->updatedAt = new DateTimeImmutable('2024-01-02T03:04:05+00:00');

    expect($response->toArray())->toBe([
        'createdAt' => '2024-01-02',
        'updatedAt' => '2024-01-02T03:04:05+00:00',
    ]);
});
