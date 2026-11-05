<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Facades\RomaHttp;
use BYanelli\Roma\Http\Client\Attributes\Get;
use BYanelli\Roma\Http\Client\Attributes\Responses;
use BYanelli\Roma\Http\Client\Exceptions\MalformedResponseException;
use Illuminate\Support\Facades\Http;

final readonly class EmptyResponseItems
{
    /**
     * @param  list<string>  $items
     */
    public function __construct(public array $items) {}
}

final readonly class NonEmptyResponseItems
{
    /**
     * @param  non-empty-list<string>  $items
     */
    public function __construct(public array $items) {}
}

#[Get('/items')]
#[Responses(EmptyResponseItems::class)]
final readonly class EmptyGetItems {}

#[Get('/items')]
#[Responses(NonEmptyResponseItems::class)]
final readonly class NonEmptyGetItems {}

it('maps an empty list in a response', function () {
    Http::fake(['api.test/*' => Http::response(['items' => []])]);

    expect(RomaHttp::baseUrl('https://api.test')->sendRequest(new EmptyGetItems)->items)->toBe([]);
});

it('throws MalformedResponseException for an absent list', function () {
    Http::fake(['api.test/*' => Http::response(['other' => 1])]);

    expect(fn () => RomaHttp::baseUrl('https://api.test')->sendRequest(new EmptyGetItems))
        ->toThrow(MalformedResponseException::class);
});

it('throws MalformedResponseException for [] in a non-empty list', function () {
    Http::fake(['api.test/*' => Http::response(['items' => []])]);

    expect(fn () => RomaHttp::baseUrl('https://api.test')->sendRequest(new NonEmptyGetItems))
        ->toThrow(MalformedResponseException::class);
});

it('fakes a response with an empty list', function () {
    RomaHttp::fake([EmptyGetItems::class => new EmptyResponseItems([])]);

    expect(RomaHttp::baseUrl('https://api.test')->sendRequest(new EmptyGetItems))->toEqual(new EmptyResponseItems([]));
});
