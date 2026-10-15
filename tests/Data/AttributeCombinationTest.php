<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Attributes\Present;
use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Body;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Tests\TestCase;
use BYanelli\Roma\TypeScript\Attributes\InputMapsToTypeScriptBody;
use BYanelli\Roma\TypeScript\Attributes\InputMapsToTypeScriptQuery;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;
use Illuminate\Http\UploadedFile;

class TestStatusWithKey
{
    public function __construct(#[Status, Key('code')] public int $status = 200) {}
}

class TestHeaderWithStatus
{
    public function __construct(#[Header('X-Code'), Status] public int $status = 200) {}
}

class TestOptionalAndPresent
{
    public function __construct(#[Optional, Present] public ?string $bio = null) {}
}

it('rejects attribute combinations with no meaning when the definition is built', function (string $class, string $message) {
    expect(fn () => app(ObjectMapper::class)->map($class, new ArrayPayload([]), new ArrayPolicy))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'Status and Key' => [TestStatusWithKey::class, 'has both #[Status] and #[Key], but #[Status] is not written under a key'],
    'Header and Status' => [TestHeaderWithStatus::class, 'has more than one destination: #[Header] and #[Status]'],
    'Optional and Present' => [TestOptionalAndPresent::class, 'has both #[Optional] and #[Present]'],
]);

it('rejects attribute combinations with no meaning when serializing', function (object $object, string $message) {
    expect(fn () => Serializer::serialize($object))->toThrow(RuntimeException::class, $message);
})->with([
    'Status and Key' => [fn () => new TestStatusWithKey, 'has both #[Status] and #[Key]'],
    'Header and Status' => [fn () => new TestHeaderWithStatus, 'has more than one destination'],
    'Optional and Present' => [fn () => new TestOptionalAndPresent, 'has both #[Optional] and #[Present]'],
]);

class TestTwoSourcesRequest
{
    public function __construct(#[Query, Body] public string $name) {}
}

class TestUploadInQueryRequest
{
    public function __construct(#[Query] public UploadedFile $avatar) {}
}

it('rejects a source attribute that the property type or another source attribute overrides', function (string $class, string $message) {
    /** @var TestCase $this */
    $this->setRequest();

    expect(fn () => $this->mapRequest($class))->toThrow(RuntimeException::class, $message);
})->with([
    'two sources' => [TestTwoSourcesRequest::class, 'has more than one source: #[Query] and #[Body]'],
    'type source' => [TestUploadInQueryRequest::class, 'which always maps from the "file" source, so #[Query] has no effect'],
]);

class TestTsBucketOnQueryRequest
{
    public function __construct(#[Query, InputMapsToTypeScriptQuery] public string $search) {}
}

class TestTsBothBucketsRequest
{
    public function __construct(#[InputMapsToTypeScriptBody, InputMapsToTypeScriptQuery] public string $search) {}
}

class TestTsBucketItem
{
    public function __construct(#[InputMapsToTypeScriptQuery] public string $name) {}
}

class TestTsBucketNestedRequest
{
    public function __construct(public TestTsBucketItem $item) {}
}

class TestTsBucketResponse
{
    public function __construct(#[InputMapsToTypeScriptBody] public string $name) {}
}

it('rejects a TypeScript bucket attribute outside a top-level #[Input] request property', function (array $requests, array $responses, string $message) {
    expect(fn () => new TypeScriptGenerator($requests, $responses)->generate())
        ->toThrow(RuntimeException::class, $message);
})->with([
    'non-input source' => [[TestTsBucketOnQueryRequest::class], [], 'TestTsBucketOnQueryRequest::$search" has #[InputMapsToTypeScriptQuery], which applies only'],
    'both buckets' => [[TestTsBothBucketsRequest::class], [], 'has both #[InputMapsToTypeScriptBody] and #[InputMapsToTypeScriptQuery]'],
    'nested object' => [[TestTsBucketNestedRequest::class], [], 'TestTsBucketItem::$name" has #[InputMapsToTypeScriptQuery]'],
    'response' => [[], [TestTsBucketResponse::class], 'TestTsBucketResponse::$name" has #[InputMapsToTypeScriptBody]'],
]);

class TestStatusRequest
{
    public function __construct(public string $name, #[Status] public int $status = 200) {}
}

it('rejects #[Status] on a class mapped from a request or an array', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['name' => 'Bill']);

    expect(fn () => $this->mapRequest(TestStatusRequest::class))
        ->toThrow(RuntimeException::class, 'TestStatusRequest::$status" has #[Status], which RequestSourcePolicy does not allow: the data it maps from has no status')
        ->and(fn () => new TypeScriptGenerator([TestStatusRequest::class])->generate())
        ->toThrow(RuntimeException::class, 'which RequestSourcePolicy does not allow')
        ->and(fn () => app(ObjectMapper::class)->map(TestStatusRequest::class, new ArrayPayload(['name' => 'Bill']), new ArrayPolicy))
        ->toThrow(RuntimeException::class, 'which ArrayPolicy does not allow');
});

it('allows #[Status] on a response class in TypeScript generation', function () {
    expect(new TypeScriptGenerator(responses: [TestStatusRequest::class])->generate())
        ->toContain('export interface TestStatusRequestBody {')
        ->not->toContain('status');
});

class TestStringStatus
{
    #[Status]
    public string $status = '201';
}

class TestNullableStatus
{
    #[Status]
    public ?int $status = null;
}

class TestMissingStatus
{
    #[Status]
    public int|Missing $status = 201;
}

class TestUntypedStatus
{
    #[Status]
    public $status = 201;
}

it('rejects #[Status] on a property not typed int', function (string $class, string $type) {
    $message = "{$class}::\$status\" has #[Status], so its type must be int{$type}";

    expect(fn () => Serializer::serialize(new $class))->toThrow(RuntimeException::class, $message)
        ->and(fn () => new TypeScriptGenerator(responses: [$class])->generate())->toThrow(RuntimeException::class, $message);
})->with([
    [TestStringStatus::class, ', not string'],
    [TestNullableStatus::class, ', not ?int'],
    [TestMissingStatus::class, ', not BYanelli\Roma\Missing|int'],
    [TestUntypedStatus::class, '.'],
]);

class TestPresentWithDefaultParameter
{
    public function __construct(#[Present] public ?string $bio = null) {}
}

class TestPresentWithDefaultProperty
{
    #[Present]
    public ?string $bio = 'none';
}

class TestPresentWithoutDefault
{
    #[Present]
    public ?string $bio;
}

it('rejects #[Present] on a property with a default value', function (string $class) {
    $message = "Roma property \"{$class}::\$bio\" has #[Present] and a default value, which is never used";

    expect(fn () => app(ObjectMapper::class)->map($class, new ArrayPayload(['bio' => 'x']), new ArrayPolicy))
        ->toThrow(RuntimeException::class, $message)
        ->and(fn () => Serializer::toArray(new $class))->toThrow(RuntimeException::class, $message);
})->with([TestPresentWithDefaultParameter::class, TestPresentWithDefaultProperty::class]);

it('allows #[Present] on a property without a default', function () {
    $mapped = app(ObjectMapper::class)->map(TestPresentWithoutDefault::class, new ArrayPayload(['bio' => null]), new ArrayPolicy);

    expect($mapped->bio)->toBeNull()
        ->and(Serializer::toArray($mapped))->toBe(['bio' => null]);
});
