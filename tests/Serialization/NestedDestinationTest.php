<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\Serialization\IsArrayable;
use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

class TestNestedWithHeader
{
    public function __construct(
        public string $name = 'a',
        #[Header('ETag')]
        public string $etag = 'x',
    ) {}
}

class TestNestedWithStatus
{
    public function __construct(
        public string $name = 'a',
        #[Status]
        public int $status = 201,
    ) {}
}

class TestNestedWithHeaderAndOwnToArray
{
    use IsArrayable;

    #[Header('ETag')]
    public string $etag = 'x';

    public function toArray(?Profile $profile = null): array
    {
        return ['etag' => $this->etag];
    }
}

class TestNestedHeaderResponse extends Response
{
    public function __construct(public object $item) {}
}

class TestNestedHeaderListResponse extends Response
{
    /**
     * @param  list<TestNestedWithHeader>  $items
     */
    public function __construct(public array $items) {}
}

class TestNestedHeaderTypedResponse extends Response
{
    public function __construct(public TestNestedWithHeader $item) {}
}

class TestNestedStatusTypedResponse extends Response
{
    public function __construct(public TestNestedWithStatus $item) {}
}

it('throws when serializing a nested object with a header or status', function (object $object, string $message) {
    expect(fn () => Serializer::serialize($object))->toThrow(RuntimeException::class, $message);
})->with([
    'header' => [fn () => new TestNestedHeaderResponse(new TestNestedWithHeader), 'TestNestedWithHeader::$etag" has #[Header] but is inside a nested object'],
    'status' => [fn () => new TestNestedHeaderResponse(new TestNestedWithStatus), 'TestNestedWithStatus::$status" has #[Status] but is inside a nested object'],
    'list element' => [fn () => new TestNestedHeaderListResponse([new TestNestedWithHeader]), 'Destination attributes such as #[Header] and #[Status] can only be declared on the top-level class'],
    'own toArray()' => [fn () => new TestNestedHeaderResponse(new TestNestedWithHeaderAndOwnToArray), 'TestNestedWithHeaderAndOwnToArray::$etag" has #[Header]'],
]);

it('writes the header of the same class at the top level', function () {
    $serialized = Serializer::serialize(new TestNestedWithHeader);

    expect($serialized->headers)->toBe(['ETag' => 'x'])
        ->and($serialized->body)->toBe(['name' => 'a']);
});

it('throws when the definition of a nested object with a header or status is built', function (string $class, string $message) {
    expect(fn () => Mapper::fromArray($class, []))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'header' => [TestNestedHeaderTypedResponse::class, 'TestNestedWithHeader::$etag" has #[Header] but is inside a nested object'],
    'status' => [TestNestedStatusTypedResponse::class, 'TestNestedWithStatus::$status" has #[Status] but is inside a nested object'],
]);

it('throws when generating TypeScript for a nested object with a header', function () {
    expect(fn () => new TypeScriptGenerator(responses: [TestNestedHeaderTypedResponse::class])->generate())
        ->toThrow(RuntimeException::class, 'Destination attributes such as #[Header] and #[Status] can only be declared on the top-level class');
});
