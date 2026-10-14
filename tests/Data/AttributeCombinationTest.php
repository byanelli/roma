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
