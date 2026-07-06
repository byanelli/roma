<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Header;
use BYanelli\Roma\Tests\TestCase;

readonly class TestDottedKeyRequest
{
    #[Header('X.Weird')]
    public string $weird;
}

readonly class TestStarKeyRequest
{
    #[Header('X*Weird')]
    public string $weird;
}

it('rejects a request key containing a dot', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['X.Weird' => 'hello']);

    expect(fn () => $this->mapRequest(TestDottedKeyRequest::class))
        ->toThrow(RuntimeException::class, "may not contain '.' or '*'");
});

it('rejects a request key containing a star', function () {
    /** @var TestCase $this */
    $this->setRequest();

    expect(fn () => $this->mapRequest(TestStarKeyRequest::class))
        ->toThrow(RuntimeException::class, "may not contain '.' or '*'");
});

readonly class TestPlainKeyRequest
{
    public string $name;
}

it('leaves dotted/starred keys in the client data alone (they are just unmapped)', function () {
    /** @var TestCase $this */
    // A client may send arbitrary field names; ones we don't map are ignored
    // and must not disturb a normal property or crash the build.
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['name' => 'Bill', 'weird.key' => 'junk', 'a*b' => 'z'],
    );

    $request = $this->mapRequest(TestPlainKeyRequest::class);

    expect($request->name)->toBe('Bill');
});
