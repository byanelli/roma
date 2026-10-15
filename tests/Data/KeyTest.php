<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Body;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Input;
use BYanelli\Roma\Http\Attributes\Query;
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
    #[Header('X-Foo'), Key('foo')]
    public string $foo = 'bar';
}

class TestBodyAndKey
{
    #[Body('a'), Key('b')]
    public string $value = 'v';
}

it('rejects a source key with #[Key] when serializing', function () {
    expect(fn () => Serializer::toArray(new TestBodyAndKey))
        ->toThrow(RuntimeException::class, 'gets a key from both #[Body] and #[Key]');
});

readonly class TestSourceKeyRoundTrip
{
    public function __construct(
        #[Query('page_num')] public int $page,
        #[Body('display.name')] public string $name,
        #[Input('sort_by')] public string $sort,
        #[Query] public bool $draft,
    ) {}
}

it('serializes a property under the key from its source attribute', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['page_num' => '2', 'sort_by' => 'date', 'draft' => '1'], headers: ['Content-Type' => 'application/json'], json: ['display.name' => 'Bill']);

    $request = $this->mapRequest(TestSourceKeyRoundTrip::class);

    expect(Serializer::toArray($request))->toBe(['page_num' => 2, 'display.name' => 'Bill', 'sort_by' => 'date', 'draft' => true]);
});

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

    $mapped = Mapper::fromArray(TestKeyRoundTrip::class, $data);

    expect($mapped->id)->toBe(42)
        ->and($mapped->address->street)->toBe('Main St')
        ->and(Serializer::toArray($mapped))->toBe($data);
});

class TestHeaderRoundTrip
{
    public function __construct(
        #[Header('X-Request-Id')] public string $requestId,
        public string $name,
    ) {}
}

it('reads #[Header] from a request and writes it to a header', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['name' => 'Bill'], headers: ['X-Request-Id' => 'abc']);

    $serialized = Serializer::serialize($this->mapRequest(TestHeaderRoundTrip::class));

    expect($serialized->headers)->toBe(['X-Request-Id' => 'abc'])
        ->and($serialized->body)->toBe(['name' => 'Bill']);
});

class TestEmptyKey
{
    #[Key('')]
    public string $name = 'x';
}

class TestEmptyQueryKey
{
    #[Query('')]
    public string $name = 'x';
}

class TestEmptyBodyKey
{
    #[Body('')]
    public string $name = 'x';
}

class TestEmptyHeaderName
{
    #[Header('')]
    public string $name = 'x';
}

it('rejects an empty key', function (string $class, string $message) {
    /** @var TestCase $this */
    $this->setRequest();
    $message = "Roma property \"{$class}::\$name\" has {$message}";

    expect(fn () => $this->mapRequest($class))->toThrow(RuntimeException::class, $message)
        ->and(fn () => Serializer::toArray(new $class))->toThrow(RuntimeException::class, $message);
})->with([
    [TestEmptyKey::class, "#[Key('')], an empty key. Give a key, or remove #[Key] to use the property name."],
    [TestEmptyQueryKey::class, "#[Query('')], an empty key. Give a key, or use #[Query] without one to use the property name."],
    [TestEmptyBodyKey::class, "#[Body('')], an empty key."],
    [TestEmptyHeaderName::class, "#[Header('')], an empty key. Give a key."],
]);
