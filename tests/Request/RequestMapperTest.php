<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Accessors\Ajax;
use BYanelli\Roma\Request\Attributes\Accessors\Method;
use BYanelli\Roma\Request\Attributes\Header;
use BYanelli\Roma\Request\Attributes\Headers\ContentType;
use BYanelli\Roma\Request\Attributes\Rule;
use BYanelli\Roma\Request\Enums\ContentType as RomaContentType;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

enum Color
{
    case Red;
    case Green;
    case Blue;
}

enum Intensity: int
{
    case Low = 10;
    case Medium = 20;
    case High = 30;
}

trait HasQuantity
{
    #[Rule('gt:9')]
    public readonly int $quantity;
}

readonly class TestRequest
{
    use HasQuantity;

    public function __construct(
        public string $url,
        public string $name,
        public float $price,
        #[Ajax(mustBe: true)]
        public bool $isAjax,
        #[Method]
        public string $method,
        public string $default = 'foo',
    ) {}

    public DateTimeInterface $date;

    public bool $flag;

    #[Header('X-Flag')]
    public bool $flagFromHeader;

    #[ContentType]
    public string $contentType;

    // todo: Gracefully handle the failure caused by mapping the same value
    //  to two different types. Uncomment below for example
    /*#[ContentType]
    public ContentTypeEnum $contentTypeEnum;*/

    public Color $color;

    public Intensity $intensity;

    /** @var array<int> */
    public array $arr;

    public UploadedFile $myFile;
}

it('maps requests', function () {
    /** @var TestCase $this */
    $this->setRequest(
        query: [
            'url' => 'https://example.com',
            'name' => 'John Doe',
            'price' => '9.99',
            'quantity' => '10',
            'date' => '2024-01-01',
            'flag' => 'true',
            'color' => 'Red',
            'intensity' => '20',
        ],
        headers: [
            'X-Flag' => 'false',
            'X-Requested-With' => 'XMLHttpRequest',
            'Content-Type' => 'application/json',
        ],
        files: [
            'myFile' => UploadedFile::fake()->createWithContent('myFile.txt', 'zzz'),
        ],
        json: [
            'arr' => [1, 2, 3],
        ],
    );

    $request = $this->mapRequest(TestRequest::class);

    $this->assertEquals('https://example.com', $request->url);
    $this->assertEquals('John Doe', $request->name);
    $this->assertEquals('2024-01-01', $request->date->format('Y-m-d'));
    $this->assertEquals(9.99, $request->price);
    $this->assertEquals(10, $request->quantity);
    $this->assertEquals(true, $request->flag);
    $this->assertEquals(false, $request->flagFromHeader);
    $this->assertEquals('application/json', $request->contentType);
    $this->assertEquals(true, $request->isAjax);
    $this->assertEquals('GET', $request->method);
    $this->assertEquals(Color::Red, $request->color);
    $this->assertEquals(Intensity::Medium, $request->intensity);
    $this->assertEquals('foo', $request->default);
    $this->assertEquals([1, 2, 3], $request->arr);
    $this->assertEquals('myFile.txt', $request->myFile->getClientOriginalName());
    $this->assertEquals('zzz', $request->myFile->getContent());
});

it('fails to map invalid requests', function () {
    /** @var TestCase $this */
    $this->setRequest(
        query: [
            'url' => 'https://example.com',
            'price' => '9.99.9',
            'quantity' => '8',
            'date' => 'jijiji',
            'flag' => 'truee',
            'color' => 'Orange',
            'intensity' => '40',
        ],
        headers: [
            'X-Flag' => 'falsee',
            'Content-Type' => 'application/json',
        ],
        files: [
            'myFile' => UploadedFile::fake()->createWithContent('myFile.txt', 'zzz'),
        ],
        json: [
            'arr' => ['foo', 'bar', 'baz'],
        ],
    );

    try {
        $this->mapRequest(TestRequest::class);
    } catch (ValidationException $e) {
        $this->assertEquals([
            'input.price' => [
                'The input.price field must be a number.',
            ],
            'request.ajax' => [
                'The request.ajax field must be accepted.',
            ],
            'input.quantity' => [
                'The input.quantity field must be greater than 9.',
            ],
            'input.date' => [
                'The input.date field must be a valid date.',
            ],
            'input.flag' => [
                'The input.flag field must be true or false.',
            ],
            'header.X-Flag' => [
                'The header.X-Flag field must be true or false.',
            ],
            'input.color' => [
                // todo: better error messages for enums
                'The selected input.color is invalid.',
            ],
            'input.intensity' => [
                'The selected input.intensity is invalid.',
            ],
            'input.name' => [
                'The input.name field is required.',
            ],
            'input.arr.0' => [
                'The input.arr.* field must be an integer.',
            ],
            'input.arr.1' => [
                'The input.arr.* field must be an integer.',
            ],
            'input.arr.2' => [
                'The input.arr.* field must be an integer.',
            ],
        ], $e->errors());

        return;
    }

    $this->assertTrue(false, 'Exception was not thrown');
});

readonly class TestNestedFileChild
{
    public string $label;

    public UploadedFile $doc;
}

readonly class TestNestedFileRequest
{
    public TestNestedFileChild $attachment;
}

it('rejects a file upload inside a nested object', function () {
    /** @var TestCase $this */
    $this->setRequest(
        query: ['attachment' => ['label' => 'x']],
        files: ['doc' => UploadedFile::fake()->createWithContent('doc.txt', 'z')],
    );

    expect(fn () => $this->mapRequest(TestNestedFileRequest::class))
        ->toThrow(RuntimeException::class, 'file upload inside a nested request object, which is not supported');
});

enum ContentTypeEnum: string
{
    case ApplicationJson = 'application/json';
}

readonly class TestItMapsContentTypeAsAnEnum
{
    #[ContentType]
    public ContentTypeEnum $contentType;
}

it('maps header values to enums', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: [
            'Content-Type' => 'application/json',
        ],
    );

    $request = $this->mapRequest(TestItMapsContentTypeAsAnEnum::class);

    $this->assertEquals(ContentTypeEnum::ApplicationJson, $request->contentType);
});

readonly class TestIntBackedEnumRequest
{
    public Intensity $intensity;
}

it('maps a JSON integer to an int-backed enum', function () {
    /** @var TestCase $this */
    // JSON delivers a real int (not a numeric string), which the coercer must
    // accept for an int-backed enum.
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['intensity' => 20],
    );

    $request = $this->mapRequest(TestIntBackedEnumRequest::class);

    expect($request->intensity)->toBe(Intensity::Medium);
});

readonly class TestSubSubObject
{
    public string $floob;
}

readonly class TestSubObject
{
    public string $bar;

    public string $baz;

    public TestSubSubObject $subSubObject;
}

readonly class TestItMapsSubObjects
{
    public string $foo;

    public TestSubObject $subObject;
}

it('maps nested objects', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: [
            'Content-Type' => 'application/json',
        ],
        json: [
            'foo' => 'bar',
            'subObject' => [
                'bar' => 'baz',
                'baz' => 'quux',
                'subSubObject' => [
                    'floob' => 'flerb',
                ],
            ],
        ],
    );

    $request = $this->mapRequest(TestItMapsSubObjects::class);

    $this->assertEquals('bar', $request->foo);
    $this->assertEquals('baz', $request->subObject->bar);
    $this->assertEquals('quux', $request->subObject->baz);
    $this->assertEquals('flerb', $request->subObject->subSubObject->floob);
});

#[Ajax]
readonly class TestItRequiresAjax {}

it('requires accessor values to be true via class attributes', function () {
    /** @var TestCase $this */
    $this->setRequest();

    try {
        $this->mapRequest(TestItRequiresAjax::class);
    } catch (ValidationException $e) {
        $this->assertEquals(
            ['request.ajax' => ['The request.ajax field must be accepted.']],
            $e->errors()
        );

        return;
    }

    $this->assertTrue(false, 'Exception was not thrown');
});

#[ContentType(ContentType::APPLICATION_JSON)]
class TestItRequiresApplicationJsonContentType {}

it('requires header values to be valid via class attributes', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: [
            'Content-Type' => 'multipart/form-data',
        ],
    );

    $thrown = false;

    try {
        $this->mapRequest(TestItRequiresApplicationJsonContentType::class);
    } catch (ValidationException $e) {
        $thrown = true;

        $this->assertEquals(
            ['header.Content-Type' => ['The selected header.Content-Type is invalid.']],
            $e->errors()
        );
    }

    $this->assertTrue($thrown);
});

#[ContentType(RomaContentType::Json)]
class TestItAcceptsContentTypeEnumConstraint
{
    public string $data;
}

it('accepts the ContentType enum as a class-level #[ContentType] constraint', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['data' => 'ok'],
    );

    $request = $this->mapRequest(TestItAcceptsContentTypeEnumConstraint::class);

    expect($request->data)->toBe('ok');
});

it('rejects a mismatched content type against an enum #[ContentType] constraint', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'text/html']);

    try {
        $this->mapRequest(TestItAcceptsContentTypeEnumConstraint::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('header.Content-Type');
    }
});
