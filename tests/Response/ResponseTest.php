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
