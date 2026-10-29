<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Delimited;
use BYanelli\Roma\Attributes\Unit;
use BYanelli\Roma\Config\ConfigObject;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\JsonSchema\ClassSchema;
use BYanelli\Roma\Tests\TestCase;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;
use BYanelli\Roma\Values\ByteSize;
use Carbon\CarbonInterval;

function valueErrors(string $class, array $data): array
{
    try {
        Mapper::fromArray($class, $data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a MappingValidationException');
}

final readonly class DelimitedHolder
{
    /**
     * @param  list<string>  $hosts
     * @param  list<int>  $ports
     */
    public function __construct(
        #[Delimited]
        public array $hosts = [],
        #[Delimited('|')]
        public array $ports = [],
    ) {}
}

it('splits a delimited string, trimming items and dropping empty ones', function () {
    expect(Mapper::fromArray(DelimitedHolder::class, ['hosts' => ' a.com, b.com,, c.com ,', 'ports' => '80| 443']))
        ->toEqual(new DelimitedHolder(['a.com', 'b.com', 'c.com'], [80, 443]));
});

it('uses an array value as it is', function () {
    expect(Mapper::fromArray(DelimitedHolder::class, ['hosts' => ['a.com', 'b,c'], 'ports' => ['80']]))
        ->toEqual(new DelimitedHolder(['a.com', 'b,c'], [80]));
});

it('validates the split items', function () {
    expect(valueErrors(DelimitedHolder::class, ['ports' => '80|http']))
        ->toBe(['data.ports.1' => ['The data.ports.1 field must be an integer.']]);
});

final readonly class DelimitedQuery
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        #[Query]
        #[Delimited]
        public array $tags,
    ) {}
}

it('splits a delimited query parameter', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['tags' => 'php,laravel']);

    expect($this->mapRequest(DelimitedQuery::class)->tags)->toBe(['php', 'laravel']);
});

final readonly class DelimitedOnString
{
    public function __construct(
        #[Delimited]
        public string $hosts,
    ) {}
}

it('rejects #[Delimited] on a property that is not an array', function () {
    expect(fn () => Mapper::fromArray(DelimitedOnString::class, ['hosts' => 'a']))
        ->toThrow(RuntimeException::class, 'Roma property "DelimitedOnString::$hosts" has #[Delimited], which splits a string into an array, but its type is not an array.');
});

final readonly class IntervalHolder
{
    public function __construct(
        public CarbonInterval $timeout,
        #[Unit('seconds')]
        public ?CarbonInterval $retryAfter = null,
        #[Unit('minutes')]
        public ?DateInterval $ttl = null,
    ) {}
}

it('maps a duration string to a CarbonInterval', function (string $value, int $seconds) {
    expect(Mapper::fromArray(IntervalHolder::class, ['timeout' => $value])->timeout->totalSeconds)->toEqual($seconds);
})->with([
    ['90 seconds', 90],
    ['1 hour 30 minutes', 5400],
    ['PT90S', 90],
    ['2h 5m', 7500],
]);

it('maps a number in the #[Unit]', function () {
    $holder = Mapper::fromArray(IntervalHolder::class, ['timeout' => '1 minute', 'retryAfter' => '45', 'ttl' => 1.5]);

    expect($holder->retryAfter)->toBeInstanceOf(CarbonInterval::class)
        ->and($holder->retryAfter->totalSeconds)->toEqual(45)
        ->and($holder->ttl)->toBeInstanceOf(CarbonInterval::class)
        ->and(CarbonInterval::instance($holder->ttl)->totalSeconds)->toEqual(90);

    expect(Mapper::fromArray(IntervalHolder::class, ['timeout' => '1 minute', 'retryAfter' => 30])->retryAfter->totalSeconds)->toEqual(30)
        ->and(Mapper::fromArray(IntervalHolder::class, ['timeout' => '1 minute', 'retryAfter' => '2 minutes'])->retryAfter->totalSeconds)->toEqual(120);
});

it('rejects a value that is not a duration', function () {
    expect(valueErrors(IntervalHolder::class, ['timeout' => '90', 'retryAfter' => 'soon', 'ttl' => 'later']))->toBe([
        'data.timeout' => ['The data.timeout field must be a duration such as "90 seconds".'],
        'data.retryAfter' => ['The data.retryAfter field must be a number of seconds or a duration such as "90 seconds".'],
        'data.ttl' => ['The data.ttl field must be a number of minutes or a duration such as "90 seconds".'],
    ]);
});

it('requires a required interval', function () {
    expect(valueErrors(IntervalHolder::class, []))->toBe(['data.timeout' => ['The data.timeout field is required.']]);
});

final readonly class UnitOnInt
{
    public function __construct(
        #[Unit('seconds')]
        public int $timeout,
    ) {}
}

it('rejects #[Unit] on a property that is not an interval', function () {
    expect(fn () => Mapper::fromArray(UnitOnInt::class, ['timeout' => 1]))
        ->toThrow(RuntimeException::class, 'Roma property "UnitOnInt::$timeout" has #[Unit], which applies only to a CarbonInterval or DateInterval property.');
});

it('rejects an unknown unit', function () {
    expect(fn () => new Unit('fortnights'))
        ->toThrow(RuntimeException::class, "Roma #[Unit('fortnights')] is not a unit CarbonInterval accepts");
});

final readonly class ByteSizeHolder
{
    public function __construct(
        public ByteSize $maxUpload,
        public ByteSize $chunk = new ByteSize(1024),
    ) {}
}

it('maps a byte size from a string or an int', function (mixed $value, int $bytes) {
    expect(Mapper::fromArray(ByteSizeHolder::class, ['maxUpload' => $value])->maxUpload->bytes)->toBe($bytes);
})->with([
    ['10MB', 10_000_000],
    ['512KiB', 524_288],
    ['1.5 gb', 1_500_000_000],
    ['2TiB', 2 * 1024 ** 4],
    ['100', 100],
    ['100B', 100],
    [2048, 2048],
]);

it('uses the default byte size', function () {
    expect(Mapper::fromArray(ByteSizeHolder::class, ['maxUpload' => 1])->chunk)->toEqual(new ByteSize(1024));
});

it('rejects a value that is not a byte size', function (mixed $value) {
    expect(valueErrors(ByteSizeHolder::class, ['maxUpload' => $value]))
        ->toBe(['data.maxUpload' => ['The data.maxUpload field must be a size in bytes, or with a unit such as "10MB" or "512KiB".']]);
})->with([
    'unknown unit' => ['10M'],
    'negative' => ['-5'],
    'fraction of a byte' => ['0.5B'],
    'too large' => ['99999999999TB'],
]);

it('requires a required byte size', function () {
    expect(valueErrors(ByteSizeHolder::class, ['maxUpload' => '']))
        ->toBe(['data.maxUpload' => ['The data.maxUpload field is required.']]);
});

it('parses a byte size by hand', function () {
    expect(ByteSize::parse('1.1KB')->bytes)->toBe(1100)
        ->and(fn () => ByteSize::parse('lots'))->toThrow(InvalidArgumentException::class, '"lots" is not a byte size')
        ->and(fn () => new ByteSize(-1))->toThrow(InvalidArgumentException::class);
});

it('serializes a byte size as its number of bytes, which maps back', function () {
    $holder = Mapper::fromArray(ByteSizeHolder::class, ['maxUpload' => '1KiB']);

    expect(Serializer::toArray($holder))->toBe(['maxUpload' => '1024', 'chunk' => '1024'])
        ->and(Mapper::fromArray(ByteSizeHolder::class, Serializer::toArray($holder)))->toEqual($holder);
});

it('describes intervals and byte sizes as strings in TypeScript and JSON Schema', function () {
    $typescript = new TypeScriptGenerator(responses: [IntervalHolder::class, ByteSizeHolder::class])->generate();

    expect($typescript)
        ->toContain('  timeout: string;')
        ->toContain('  maxUpload: string;')
        ->and(ClassSchema::properties(IntervalHolder::class)['timeout']->toArray()['type'])->toBe('string')
        ->and(ClassSchema::properties(ByteSizeHolder::class)['maxUpload']->toArray()['type'])->toBe('string');
});

#[ConfigObject('services.uploads')]
final readonly class UploadsConfig
{
    /**
     * @param  list<string>  $mimeTypes
     */
    public function __construct(
        #[Delimited]
        public array $mimeTypes = ['image/png'],
        #[Unit('seconds')]
        public CarbonInterval $timeout = new CarbonInterval(seconds: 30),
        public ByteSize $maxSize = new ByteSize(10_000_000),
    ) {}
}

it('maps the value types from config strings', function () {
    config(['services.uploads' => ['mime_types' => 'image/png, image/webp', 'timeout' => '90', 'max_size' => '25MB']]);

    $uploads = Mapper::fromConfig(UploadsConfig::class);

    expect($uploads->mimeTypes)->toBe(['image/png', 'image/webp'])
        ->and($uploads->timeout->totalSeconds)->toEqual(90)
        ->and($uploads->maxSize->bytes)->toBe(25_000_000);
});

it('applies the defaults of the value types to blank config values', function () {
    config(['services.uploads' => ['mime_types' => '', 'timeout' => ' ', 'max_size' => null]]);

    $uploads = Mapper::fromConfig(UploadsConfig::class);

    expect($uploads->mimeTypes)->toBe(['image/png'])
        ->and($uploads->timeout->totalSeconds)->toEqual(30)
        ->and($uploads->maxSize->bytes)->toBe(10_000_000);
});

it('rejects an empty delimiter', function () {
    expect(fn () => new Delimited(''))->toThrow(RuntimeException::class, 'Roma #[Delimited] needs a delimiter');
});
