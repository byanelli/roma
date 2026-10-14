<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Request\Attributes\Guard;
use BYanelli\Roma\Request\Attributes\Query;
use BYanelli\Roma\Request\Attributes\Rule;
use BYanelli\Roma\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/*
| These tests map plain arrays through the core with ArrayPayload and
| ArrayPolicy, without an HTTP request.
*/

enum ArrayMappedStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

readonly class ArrayMappedAddress
{
    public function __construct(
        public string $city,
        public ?string $postcode = null,
    ) {}
}

class ArrayMappedGuardLog
{
    /** @var list<string> */
    public array $calls = [];
}

readonly class ArrayMappedPost
{
    /**
     * @param  list<int>  $tagIds
     */
    public function __construct(
        #[Rule('email')]
        public string $author,
        public int $views,
        public ArrayMappedStatus $status,
        public CarbonImmutable $publishedAt,
        public ArrayMappedAddress $address,
        public array $tagIds,
        public bool $featured = false,
    ) {}

    #[Guard]
    public function logMapped(ArrayMappedGuardLog $log): void
    {
        $log->calls[] = $this->author;
    }
}

function mapArray(string $class, array $data): mixed
{
    return app(ObjectMapper::class)->map($class, new ArrayPayload($data), new ArrayPolicy);
}

function validPostData(array $overrides = []): array
{
    return array_replace([
        'author' => 'bill@example.com',
        'views' => '42',
        'status' => 'published',
        'publishedAt' => '2026-10-13 09:30:00',
        'address' => ['city' => 'Boston'],
        'tagIds' => ['1', '2', '3'],
    ], $overrides);
}

it('maps and coerces a plain array without a request', function () {
    $post = mapArray(ArrayMappedPost::class, validPostData());

    expect($post)->toBeInstanceOf(ArrayMappedPost::class)
        ->and($post->author)->toBe('bill@example.com')
        ->and($post->views)->toBe(42)
        ->and($post->status)->toBe(ArrayMappedStatus::Published)
        ->and($post->publishedAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($post->publishedAt->toDateTimeString())->toBe('2026-10-13 09:30:00')
        ->and($post->address)->toEqual(new ArrayMappedAddress('Boston'))
        ->and($post->tagIds)->toBe([1, 2, 3])
        ->and($post->featured)->toBeFalse();
});

it('reports validation errors under the data source prefix', function () {
    $data = validPostData([
        'author' => 'not-an-email',
        'views' => 'many',
        'status' => 'archived',
        'publishedAt' => 'not a date',
        'address' => ['postcode' => '02101'],
        'tagIds' => ['1', 'two'],
    ]);

    try {
        mapArray(ArrayMappedPost::class, $data);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing([
            'data.author',
            'data.views',
            'data.status',
            'data.publishedAt',
            'data.address.city',
            'data.tagIds.1',
        ]);
    }
});

it('reports a missing required key under the data source prefix', function () {
    $data = validPostData();
    unset($data['views']);

    expect(fn () => mapArray(ArrayMappedPost::class, $data))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('data.views'));
});

it('runs guards after mapping a plain array', function () {
    /** @var TestCase $this */
    $this->app->instance(ArrayMappedGuardLog::class, $log = new ArrayMappedGuardLog);

    mapArray(ArrayMappedPost::class, validPostData());

    expect($log->calls)->toBe(['bill@example.com']);
});

it('does not run guards when validation fails', function () {
    /** @var TestCase $this */
    $this->app->instance(ArrayMappedGuardLog::class, $log = new ArrayMappedGuardLog);

    try {
        mapArray(ArrayMappedPost::class, validPostData(['author' => 'nope']));
    } catch (ValidationException) {
    }

    expect($log->calls)->toBe([]);
});

readonly class ArrayMappedWithQuery
{
    #[Query]
    public string $term;
}

it('rejects a source the array policy does not allow', function () {
    expect(fn () => mapArray(ArrayMappedWithQuery::class, ['term' => 'x']))
        ->toThrow(RuntimeException::class, 'maps from the "query" source, which ArrayPolicy does not allow');
});

readonly class ArrayMappedSharedClass
{
    public string $name;
}

it('caches a definition separately for each policy', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['name' => 'from request']);

    $fromRequest = $this->mapRequest(ArrayMappedSharedClass::class);
    $fromArray = mapArray(ArrayMappedSharedClass::class, ['name' => 'from array']);

    expect($fromRequest->name)->toBe('from request')
        ->and($fromArray->name)->toBe('from array');

    try {
        mapArray(ArrayMappedSharedClass::class, []);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['data.name']);
    }
});
