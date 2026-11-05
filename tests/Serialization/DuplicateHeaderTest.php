<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Headers\ContentType;
use BYanelli\Roma\Http\Client\Attributes\Post;
use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\Serialization\Profile;

readonly class HeaderTwiceInCaseResponse
{
    public function __construct(
        #[Header('X-Flag')]
        public string $first,

        #[Header('x-flag')]
        public string $second,
    ) {}
}

readonly class HeaderTwiceWithSeparatorResponse
{
    public function __construct(
        #[Header('X-Flag')]
        public string $first,

        #[Header('X_Flag')]
        public string $second,
    ) {}
}

readonly class HeaderTwiceWithPremadeResponse
{
    public function __construct(
        #[ContentType]
        public string $first,

        #[Header('content-type')]
        public string $second,
    ) {}
}

class HeaderTwiceResponsable extends Response
{
    public function __construct(
        #[Header('X-Flag')]
        public string $first,

        #[Header('x-flag')]
        public string $second,
    ) {}
}

#[Post('/flags')]
readonly class HeaderTwiceClientRequest
{
    public function __construct(
        #[Header('X-Flag')]
        public string $first,

        #[Header('X-FLAG')]
        public string $second,
    ) {}
}

readonly class DistinctHeadersResponse
{
    public function __construct(
        #[Header('X-Flag')]
        public string $first,

        #[Header('X-Flags')]
        public string $second,

        #[Header('XFlag')]
        public string $third,
    ) {}
}

readonly class HeaderBesideBodyKeyResponse
{
    public function __construct(
        #[Header('flag')]
        public string $header,

        #[Key('flag')]
        public string $body,
    ) {}
}

it('rejects two headers whose names differ by case or separator', function (object $object, string $class, string $first, string $second) {
    expect(fn () => Serializer::serialize($object))->toThrow(
        RuntimeException::class,
        "Roma class {$class} writes \$first to \"{$first}\" and \$second to \"{$second}\", so one would replace the other. Give one of them another key.",
    );
})->with([
    'case' => [new HeaderTwiceInCaseResponse('a', 'b'), 'HeaderTwiceInCaseResponse', 'X-Flag', 'x-flag'],
    'separator' => [new HeaderTwiceWithSeparatorResponse('a', 'b'), 'HeaderTwiceWithSeparatorResponse', 'X-Flag', 'X_Flag'],
    'pre-made attribute' => [new HeaderTwiceWithPremadeResponse('a', 'b'), 'HeaderTwiceWithPremadeResponse', 'Content-Type', 'content-type'],
]);

it('rejects two headers when sending a response', function () {
    expect(fn () => new HeaderTwiceResponsable('a', 'b')->toResponse(request()))->toThrow(
        RuntimeException::class,
        'Roma class HeaderTwiceResponsable writes $first to "X-Flag" and $second to "x-flag"',
    );
});

it('rejects two headers on a client request', function () {
    expect(fn () => Serializer::serialize(new HeaderTwiceClientRequest('a', 'b'), Profile::clientRequest()))->toThrow(
        RuntimeException::class,
        'Roma class HeaderTwiceClientRequest writes $first to "X-Flag" and $second to "X-FLAG"',
    );
});

it('allows headers with different names', function () {
    expect(Serializer::serialize(new DistinctHeadersResponse('a', 'b', 'c'))->headers)
        ->toBe(['X-Flag' => 'a', 'X-Flags' => 'b', 'XFlag' => 'c']);
});

it('allows a header with the name of a body key', function () {
    $serialized = Serializer::serialize(new HeaderBesideBodyKeyResponse('a', 'b'));

    expect($serialized->headers)->toBe(['flag' => 'a'])
        ->and($serialized->body)->toBe(['flag' => 'b']);
});
