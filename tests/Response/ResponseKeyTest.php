<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Response\Attributes\Key;
use BYanelli\Roma\Response\Attributes\Optional;
use BYanelli\Roma\Response\Response;

class TestKeyRemapResponse extends Response
{
    public function __construct(
        public string $name,
        #[Key('user_id')] public int $id,
    ) {}
}

class TestOptionalKeyResponse extends Response
{
    #[Key('avatar_url'), Optional]
    public string $avatar;

    public function __construct(public string $name) {}
}

it('serializes a property under its #[Key] override', function () {
    expect((new TestKeyRemapResponse('Bill', 42))->toArray())
        ->toBe(['name' => 'Bill', 'user_id' => 42]);
});

it('applies #[Key] when the optional property is set', function () {
    $response = new TestOptionalKeyResponse('Bill');
    $response->avatar = 'http://example.test/a.png';

    expect($response->toArray())
        ->toEqual(['name' => 'Bill', 'avatar_url' => 'http://example.test/a.png']);
});

it('omits an unset optional #[Key] property', function () {
    expect((new TestOptionalKeyResponse('Bill'))->toArray())
        ->toEqual(['name' => 'Bill']);
});
