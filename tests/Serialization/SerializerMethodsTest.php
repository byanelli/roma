<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\Serialization\IsArrayable;
use Illuminate\Contracts\Support\Arrayable;

enum MethodsTier: string
{
    case Gold = 'gold';
}

class MethodsMember implements Arrayable, JsonSerializable
{
    use IsArrayable;

    public function __construct(
        public string $name,
        public MethodsTier $tier,
        public DateTimeImmutable $joinedAt,
    ) {}
}

class MethodsResponse extends Response
{
    #[Status]
    public int $status = 201;

    #[Header('X-Tier')]
    public MethodsTier $headerTier = MethodsTier::Gold;

    public function __construct(public MethodsTier $tier) {}
}

function methodsMember(): MethodsMember
{
    return new MethodsMember('Ann', MethodsTier::Gold, new DateTimeImmutable('2025-01-02T03:04:05+00:00'));
}

it('writes backed enums as their value from toArray() and toSafeArray()', function () {
    $expected = ['name' => 'Ann', 'tier' => 'gold', 'joinedAt' => '2025-01-02T03:04:05+00:00'];

    expect(Serializer::toArray(methodsMember()))->toBe($expected)
        ->and(Serializer::toSafeArray(methodsMember()))->toBe($expected)
        ->and(methodsMember()->toArray())->toBe($expected)
        ->and(methodsMember()->toSafeArray())->toBe($expected)
        ->and(Mapper::fromArray(MethodsMember::class, methodsMember()->toArray()))->toEqual(methodsMember());
});

it('writes backed enums as name and value from toResponseBody() and JSON', function () {
    $expected = ['name' => 'Ann', 'tier' => ['name' => 'Gold', 'value' => 'gold'], 'joinedAt' => '2025-01-02T03:04:05+00:00'];

    expect(Serializer::toResponseBody(methodsMember()))->toBe($expected)
        ->and(methodsMember()->toResponseBody())->toBe($expected)
        ->and(json_decode(json_encode(methodsMember()), true))->toBe($expected);
});

it('throws for a status or header property in toArray() and toSafeArray()', function (string $method) {
    expect(fn () => Serializer::$method(new MethodsResponse(MethodsTier::Gold)))
        ->toThrow(RuntimeException::class, 'MethodsResponse::$status is written to the Status destination');
})->with(['toArray', 'toSafeArray']);

it('leaves status and header properties out of toResponseBody()', function () {
    expect(Serializer::toResponseBody(new MethodsResponse(MethodsTier::Gold)))->toBe(['tier' => ['name' => 'Gold', 'value' => 'gold']])
        ->and(Serializer::serialize(new MethodsResponse(MethodsTier::Gold))->headers)->toBe(['X-Tier' => 'gold']);
});
