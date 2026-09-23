<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Tests\Support\NamespacedItem;
use BYanelli\Roma\Tests\Support\NamespacedListRequest;
use BYanelli\Roma\Tests\Support\Nested\FqcnListRequest;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

readonly class ListIntPropertyRequest
{
    /** @var list<int> */
    public array $ids;
}

it('maps a property documented as list<T>', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['ids' => ['1', '2', '3']],
    );

    $request = $this->mapRequest(ListIntPropertyRequest::class);

    expect($request->ids)->toBe([1, 2, 3]);
});

readonly class ListCtorRequest
{
    /** @param list<string> $to */
    public function __construct(public array $to) {}
}

it('maps a constructor parameter documented as list<T>', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['to' => ['a@example.com', 'b@example.com']],
    );

    $request = $this->mapRequest(ListCtorRequest::class);

    expect($request->to)->toBe(['a@example.com', 'b@example.com']);
});

enum ListStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}

readonly class ListEnumRequest
{
    /** @var list<ListStatus> */
    public array $statuses;
}

it('maps a list of enums', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['statuses' => ['open', 'closed']],
    );

    $request = $this->mapRequest(ListEnumRequest::class);

    expect($request->statuses)->toBe([ListStatus::Open, ListStatus::Closed]);
});

it('maps a list of objects declared by a same-namespace short name', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['items' => [['name' => 'a'], ['name' => 'b']]],
    );

    $request = $this->mapRequest(NamespacedListRequest::class);

    expect($request->items)->toHaveCount(2)
        ->and($request->items[0])->toBeInstanceOf(NamespacedItem::class)
        ->and($request->items[1]->name)->toBe('b');
});

it('maps a list of objects declared by FQCN', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['items' => [['name' => 'a']]],
    );

    $request = $this->mapRequest(FqcnListRequest::class);

    expect($request->items[0])->toBeInstanceOf(NamespacedItem::class);
});

it('rejects a list<T> input whose keys are not sequential', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['ids' => ['a' => 1, 'b' => 2]],
    );

    try {
        $this->mapRequest(ListIntPropertyRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.ids');
    }
});

readonly class ArrayKeyedInputRequest
{
    /** @var array<int> */
    public array $ids;
}

it('accepts non-sequential keys for array<T>', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['ids' => ['a' => '1', 'b' => '2']],
    );

    $request = $this->mapRequest(ArrayKeyedInputRequest::class);

    expect($request->ids)->toBe(['a' => 1, 'b' => 2]);
});

readonly class NullableListRequest
{
    /** @var list<int>|null */
    public ?array $ids;
}

it('maps a nullable list<T>', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['ids' => [1, 2]],
    );

    $request = $this->mapRequest(NullableListRequest::class);

    expect($request->ids)->toBe([1, 2]);
});

readonly class NonEmptyListRequest
{
    /** @var non-empty-list<int> */
    public array $ids;
}

it('maps a non-empty-list<T>', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['ids' => ['4']],
    );

    $request = $this->mapRequest(NonEmptyListRequest::class);

    expect($request->ids)->toBe([4]);
});

readonly class KeyedArrayRequest
{
    /** @var array<string, int> */
    public array $counts;
}

it('rejects array<K, T> with a parse error', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['counts' => ['a' => 1]],
    );

    expect(fn () => $this->mapRequest(KeyedArrayRequest::class))
        ->toThrow(RuntimeException::class, 'Error parsing array element type from type declaration: array<string, int>');
});
