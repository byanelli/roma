<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

// --- 1. Array input where a scalar is expected (trivial via query string) ---

readonly class ScratchIntReq
{
    public int $age;
}

it('array query value for int property → validation error, not crash', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['age' => ['1', '2']]);

    try {
        $this->mapRequest(ScratchIntReq::class);
        $this->fail('no exception');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.age');
    }
});

enum ScratchStatus: string
{
    case On = 'on';
}

readonly class ScratchEnumReq
{
    public ScratchStatus $status;
}

it('array query value for enum property → validation error, not crash', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['status' => ['on']]);

    try {
        $this->mapRequest(ScratchEnumReq::class);
        $this->fail('no exception');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.status');
    }
});

readonly class ScratchBoolReq
{
    public bool $active;
}

it('array query value for bool property → validation error, not crash', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['active' => ['x']]);

    try {
        $this->mapRequest(ScratchBoolReq::class);
        $this->fail('no exception');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.active');
    }
});

// --- 2. Common date types ---

readonly class ScratchDateImmutableReq
{
    public DateTimeImmutable $when;
}

it('maps DateTimeImmutable', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['when' => '2024-01-01']);

    $req = $this->mapRequest(ScratchDateImmutableReq::class);
    expect($req->when->format('Y-m-d'))->toBe('2024-01-01');
});

readonly class ScratchCarbonImmutableReq
{
    public \Carbon\CarbonImmutable $when;
}

it('maps CarbonImmutable', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['when' => '2024-01-01']);

    $req = $this->mapRequest(ScratchCarbonImmutableReq::class);
    expect($req->when->format('Y-m-d'))->toBe('2024-01-01');
});

readonly class ScratchIlluminateCarbonReq
{
    public \Illuminate\Support\Carbon $when;
}

it('maps Illuminate\Support\Carbon', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['when' => '2024-01-01']);

    $req = $this->mapRequest(ScratchIlluminateCarbonReq::class);
    expect($req->when->format('Y-m-d'))->toBe('2024-01-01');
});

// --- 3. Namespaced array element types in PHPDoc ---

readonly class ScratchNsArrayReq
{
    /** @var array<BYanelli\Roma\Tests\Support\ScratchItem> */
    public array $items;
}

it('maps array of namespaced objects declared by FQCN', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['items' => [['name' => 'a'], ['name' => 'b']]],
    );

    $req = $this->mapRequest(ScratchNsArrayReq::class);
    expect($req->items[0]->name)->toBe('a');
});
