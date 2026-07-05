<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

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

class TestNullableResponse extends Response
{
    public string $name;

    public ?string $nickname;
}

it('nulls an uninitialized nullable property', function () {
    $response = new TestNullableResponse;
    $response->name = 'Bill';
    // nickname left unset

    expect($response->toArray())->toBe(['name' => 'Bill', 'nickname' => null]);
});

class TestRequiredResponse extends Response
{
    public string $name;
}

it('throws for an uninitialized non-nullable property', function () {
    $response = new TestRequiredResponse;

    expect(fn () => $response->toArray())->toThrow(Error::class);
});
