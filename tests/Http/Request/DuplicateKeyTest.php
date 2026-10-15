<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Headers\ContentType;
use BYanelli\Roma\Http\Attributes\Input;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

readonly class HeaderTwiceRequest
{
    public function __construct(
        #[Header('X-Flag')]
        public string $flag,
        #[Header('x-flag')]
        public string $alsoFlag,
    ) {}
}

readonly class QueryTwiceRequest
{
    public function __construct(
        #[Query('page')]
        public int $page,
        #[Query('page')]
        public int $pageAgain,
    ) {}
}

readonly class InputKeyAndNameRequest
{
    public function __construct(
        #[Input('a')]
        public string $first,
        public string $a,
    ) {}
}

readonly class SameNameInTwoSourcesRequest
{
    public function __construct(
        #[Query('a')]
        public string $fromQuery,
        public string $a,
    ) {}
}

#[ContentType('application/json')]
readonly class ContentTypeOnClassAndPropertyRequest
{
    public function __construct(
        #[Header('Content-Type')]
        public string $contentType,
    ) {}
}

it('rejects two headers that normalize to the same key', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['X-Flag' => 'on']);

    expect(fn () => $this->mapRequest(HeaderTwiceRequest::class))->toThrow(
        RuntimeException::class,
        'Roma class HeaderTwiceRequest maps $flag and $alsoFlag from the same key: "header.X-Flag" and "header.x-flag" are both read as "header.x_flag". Give one of them another key.',
    );
});

it('rejects two properties on one query key', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['page' => '1']);

    expect(fn () => $this->mapRequest(QueryTwiceRequest::class))->toThrow(
        RuntimeException::class,
        'Roma class QueryTwiceRequest maps $page and $pageAgain from the same key "query.page". Give one of them another key.',
    );
});

it('rejects a source attribute key that another property has as its name', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['a' => 'x']);

    expect(fn () => $this->mapRequest(InputKeyAndNameRequest::class))->toThrow(
        RuntimeException::class,
        'Roma class InputKeyAndNameRequest maps $first and $a from the same key "input.a".',
    );
});

it('allows one key in two sources', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['a' => 'x']);

    $request = $this->mapRequest(SameNameInTwoSourcesRequest::class);

    expect($request->fromQuery)->toBe('x')
        ->and($request->a)->toBe('x');
});

it('applies a class-level constraint to a header a property maps', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json']);

    expect($this->mapRequest(ContentTypeOnClassAndPropertyRequest::class)->contentType)->toBe('application/json');

    $this->setRequest(headers: ['Content-Type' => 'text/plain']);

    try {
        $this->mapRequest(ContentTypeOnClassAndPropertyRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['header.Content-Type' => ['The selected header.Content-Type is invalid.']]);
    }
});
