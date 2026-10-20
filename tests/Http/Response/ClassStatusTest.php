<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Response\Response;
use Illuminate\Http\Request;

#[Status(201)]
class ClassStatusCreated extends Response
{
    public string $name = 'Bill';
}

#[Status(201)]
class ClassStatusOverridden extends Response
{
    #[Status]
    public int $status = 202;
}

#[Status('2xx')]
class ClassStatusRange extends Response {}

it('sends a response with its class-level #[Status]', function () {
    $response = new ClassStatusCreated()->toResponse(new Request);

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getData(true))->toBe(['name' => 'Bill']);
});

it('prefers the #[Status] property to the class-level #[Status]', function () {
    expect(new ClassStatusOverridden()->toResponse(new Request)->getStatusCode())->toBe(202);
});

it('rejects a class-level #[Status] without a code on a server response', function () {
    expect(fn () => new ClassStatusRange()->toResponse(new Request))
        ->toThrow(RuntimeException::class, 'needs exactly one, with a code such as 201');
});

it('normalizes a status', function (int|string $code, string $pattern) {
    expect(new Status($code)->pattern)->toBe($pattern);
})->with([
    [404, '404'],
    ['404', '404'],
    ['4XX', '4xx'],
    ['default', 'default'],
]);

it('rejects a status that is not a code, a range or default', function (int|string $code, string $message) {
    expect(fn () => new Status($code))->toThrow(InvalidArgumentException::class, $message);
})->with([
    [600, 'Roma #[Status(600)] is not a status'],
    ['6xx', "Roma #[Status('6xx')] is not a status"],
    ['other', "Roma #[Status('other')] is not a status"],
]);
