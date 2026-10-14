<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

enum EnumObjColor: int
{
    case Red = 1;
    case Blue = 2;
}

readonly class EnumObjectRequest
{
    public function __construct(
        public EnumObjColor $color,
    ) {}
}

it('accepts the {name, value} object form for a backed enum', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['color' => ['name' => 'Red', 'value' => 1]],
    );

    expect($this->mapRequest(EnumObjectRequest::class)->color)->toBe(EnumObjColor::Red);
});

it('still accepts the bare scalar backing value for a backed enum', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['color' => 2],
    );

    expect($this->mapRequest(EnumObjectRequest::class)->color)->toBe(EnumObjColor::Blue);
});

it('rejects a {name, value} object whose name and value disagree', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['color' => ['name' => 'Red', 'value' => 2]],
    );

    try {
        $this->mapRequest(EnumObjectRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.color');
    }
});

it('rejects a {name, value} object with an out-of-range value', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['color' => ['name' => 'Red', 'value' => 9]],
    );

    try {
        $this->mapRequest(EnumObjectRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.color');
    }
});
