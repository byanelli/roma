<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Facades\Mapper;

readonly class KeyTwiceData
{
    public function __construct(
        #[Key('name')]
        public string $title,
        public string $name,
    ) {}
}

readonly class KeyTwiceAddress
{
    public function __construct(
        public string $city,
        #[Key('city')]
        public string $town,
    ) {}
}

readonly class KeyTwiceCustomer
{
    public function __construct(
        public KeyTwiceAddress $address,
    ) {}
}

it('rejects two properties on one key of a plain array', function () {
    expect(fn () => Mapper::fromArray(KeyTwiceData::class, ['name' => 'x']))->toThrow(
        RuntimeException::class,
        'Roma class KeyTwiceData maps $title and $name from the same key "data.name". Give one of them another key.',
    );
});

it('rejects two properties of a nested object on one key', function () {
    expect(fn () => Mapper::fromArray(KeyTwiceCustomer::class, ['address' => ['city' => 'x']]))->toThrow(
        RuntimeException::class,
        'Roma class KeyTwiceAddress maps $city and $town from the same key "city". Give one of them another key.',
    );
});
