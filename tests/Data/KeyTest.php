<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Request\Attributes\Header;
use BYanelli\Roma\Request\Attributes\Input;
use BYanelli\Roma\Request\Attributes\Query;
use BYanelli\Roma\Response\Attributes\Header as ResponseHeader;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

readonly class TestTopLevelKeyPageRequest
{
    public function __construct(
        #[Key('page_num')] public int $page,
        #[Query, Key('per_page')] public int $perPage,
    ) {}
}

it('reads a top-level #[Key] within the property source', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['page_num' => '3', 'per_page' => '20']);

    $request = $this->mapRequest(TestTopLevelKeyPageRequest::class);

    expect($request->page)->toBe(3)
        ->and($request->perPage)->toBe(20);
});

it('reports a top-level #[Key] under its key', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['per_page' => '20']);

    try {
        $this->mapRequest(TestTopLevelKeyPageRequest::class);
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.page_num');

        return;
    }

    $this->fail('Exception was not thrown');
});

class TestQueryAndKeyRequest
{
    #[Query('page_num'), Key('x')]
    public int $page;
}

class TestHeaderAndKeyRequest
{
    #[Header('X-Foo'), Key('y')]
    public string $foo;
}

class TestInputAndKeyRequest
{
    #[Input('a'), Key('b')]
    public string $value;
}

it('rejects a property that gets a key from two attributes', function (string $class, string $message) {
    /** @var TestCase $this */
    $this->setRequest();

    expect(fn () => $this->mapRequest($class))->toThrow(RuntimeException::class, $message);
})->with([
    [TestQueryAndKeyRequest::class, 'gets a key from both #[Query] and #[Key]'],
    [TestHeaderAndKeyRequest::class, 'gets a key from both #[Header] and #[Key]'],
    [TestInputAndKeyRequest::class, 'gets a key from both #[Input] and #[Key]'],
]);

class TestResponseHeaderAndKey
{
    #[ResponseHeader('X-Foo'), Key('foo')]
    public string $foo = 'bar';
}

it('rejects #[Key] on a response header', function () {
    expect(fn () => Serializer::toArray(new TestResponseHeaderAndKey))
        ->toThrow(RuntimeException::class, 'gets a key from both #[Header] and #[Key]');
});

class TestKeyRoundTripAddress
{
    #[Key('street_name')]
    public string $street;
}

class TestKeyRoundTrip
{
    public function __construct(
        #[Key('user_id')] public int $id,
        #[Key('home.address')] public TestKeyRoundTripAddress $address,
    ) {}
}

it('maps and serializes a renamed property under the same key', function () {
    $data = ['user_id' => 42, 'home.address' => ['street_name' => 'Main St']];

    $mapped = app(ObjectMapper::class)->map(TestKeyRoundTrip::class, new ArrayPayload($data), new ArrayPolicy);

    expect($mapped->id)->toBe(42)
        ->and($mapped->address->street)->toBe('Main St')
        ->and(Serializer::toArray($mapped))->toBe($data);
});
