<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\DateFormat;
use BYanelli\Roma\Attributes\Description;
use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Attributes\Present;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\JsonSchema\ClassSchema;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Tests\Support\Discriminated\Card;
use BYanelli\Roma\Tests\Support\Discriminated\PaymentMethod;
use BYanelli\Roma\Tests\Support\Discriminated\Voucher;
use BYanelli\Roma\Tests\Support\Recursive\Comment;
use BYanelli\Roma\Tests\Support\Recursive\Thread;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rules\In;

enum SchemaColor: string
{
    case Red = 'red';
    case Green = 'green';
}

enum SchemaPriority: int
{
    case Low = 1;
    case High = 2;
}

enum SchemaSize
{
    case Small;
    case Large;
}

#[Description('A postal address.')]
readonly class SchemaAddress
{
    public function __construct(
        public string $city,
        public ?string $postcode = null,
    ) {}
}

readonly class SchemaScalars
{
    public function __construct(
        public string $name,
        public int $count,
        public float $ratio,
        public bool $active,
        public DateTimeImmutable $at,
        #[DateFormat('Y-m-d')]
        public DateTimeImmutable $day,
        #[DateFormat('d/m/Y')]
        #[Description('The day it starts.')]
        public DateTimeImmutable $starts,
        public SchemaColor $color,
        public SchemaPriority $priority,
        public SchemaSize $size,
        public mixed $anything,
    ) {}
}

readonly class SchemaCollections
{
    /**
     * @param  list<string>  $tags
     * @param  array<int>  $scores
     * @param  array<string, int>  $counts
     * @param  array<int, string>  $names
     * @param  array{input: float, output?: ?float}  $price
     * @param  list<SchemaAddress>  $addresses
     * @param  list<mixed>  $extra
     */
    public function __construct(
        public array $tags,
        public array $scores,
        public array $counts,
        public array $names,
        public array $price,
        public array $addresses,
        public array $extra,
        public SchemaAddress $home,
        #[Description('Where to send mail.')]
        public ?SchemaAddress $mailing,
    ) {}
}

readonly class SchemaPresence
{
    /**
     * @param  list<int>  $ids
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public string $required,
        public ?string $nullable,
        #[Present]
        public ?string $present,
        public string $defaulted = 'x',
        #[Optional]
        public string|Missing $optional = 'y',
        #[Optional]
        public int|Missing $missing = 0,
        #[Optional]
        public ?SchemaColor $color = null,
        public SchemaColor $tint = SchemaColor::Green,
        public SchemaSize $size = SchemaSize::Large,
        public array $ids = [1, 2],
        public array $counts = [],
        public float $ratio = 0.5,
    ) {}
}

readonly class SchemaUnions
{
    /**
     * @param  list<PaymentMethod>  $fallbacks
     */
    public function __construct(
        public PaymentMethod $payment,
        #[Discriminated([Card::class, Voucher::class], key: 'kind')]
        public Card|Voucher|null $refund,
        public array $fallbacks = [],
    ) {}
}

class SchemaRules
{
    public static function dynamic(): string
    {
        return 'max:3';
    }

    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        #[Rule('min:2', 'max:10')]
        public string $name,
        #[Rule('between:1,5')]
        public int $rating,
        #[Rule('min:0.5', 'max:99.5')]
        public float $weight,
        #[Rule('min:1', 'max:3')]
        public array $tags,
        #[Rule('size:8')]
        public string $code,
        #[Rule('in:draft,published')]
        public string $status,
        #[Rule(['in:1,2,3'])]
        public int $level,
        #[Rule('email')]
        public string $email,
        #[Rule('url')]
        public string $site,
        #[Rule('uuid')]
        public string $id,
        #[Rule('date')]
        public string $date,
        #[Rule('date_format:Y-m-d')]
        public string $day,
        #[Rule('regex:/^[a-z]+\/\d+$/')]
        public string $slug,
        #[Rule('regex:#^\#\w+$#u')]
        public string $hashtag,
        #[Rule('regex:/^abc$/i')]
        public string $caseless,
        #[Rule(self::dynamic(...), 'alpha_dash', 'unique:users')]
        public string $handle,
        #[Rule(new In(['a', 'b']))]
        public string $letter,
        #[Rule('required')]
        public string $nickname = '',
    ) {}
}

readonly class SchemaDottedKeys
{
    public function __construct(
        #[Key('error.message')]
        #[Description('What went wrong.')]
        public string $message,
        #[Key('created\.at')]
        public string $createdAt,
        #[Key('error.code')]
        public int $code = 0,
        #[Key('meta.trace.id')]
        public ?string $traceId = null,
    ) {}
}

readonly class SchemaFile
{
    public function __construct(public UploadedFile $upload) {}
}

readonly class SchemaFromQuery
{
    public function __construct(
        #[Query]
        public string $term,
    ) {}
}

/**
 * @param  class-string  $class
 * @return array<string, array<string, mixed>>
 */
function schemaProperties(string $class): array
{
    return array_map(fn (Type $type) => $type->toArray(), ClassSchema::properties($class));
}

it('returns a schema per property for a tool schema() method', function () {
    $properties = ClassSchema::properties(SchemaAddress::class);

    expect($properties)->toHaveKeys(['city', 'postcode'])
        ->and($properties['city'])->toBeInstanceOf(StringType::class)
        ->and(ClassSchema::object(SchemaAddress::class))->toBeInstanceOf(ObjectType::class);
});

it('maps scalars, dates, enums and mixed', function () {
    expect(schemaProperties(SchemaScalars::class))->toBe([
        'name' => ['type' => 'string'],
        'count' => ['type' => 'integer'],
        'ratio' => ['type' => 'number'],
        'active' => ['type' => 'boolean'],
        'at' => ['format' => 'date-time', 'type' => 'string'],
        'day' => ['format' => 'date', 'type' => 'string'],
        'starts' => ['description' => 'The day it starts. Format (PHP date): d/m/Y', 'type' => 'string'],
        'color' => ['enum' => ['red', 'green'], 'type' => 'string'],
        'priority' => ['enum' => [1, 2], 'type' => 'integer'],
        'size' => ['enum' => ['Small', 'Large'], 'type' => 'string'],
        'anything' => ['type' => ['string', 'integer', 'number', 'boolean', 'object', 'array', 'null']],
    ]);
});

it('maps arrays, maps, shapes and nested objects', function () {
    $address = [
        'description' => 'A postal address.',
        'properties' => [
            'city' => ['type' => 'string'],
            'postcode' => ['type' => ['string', 'null']],
        ],
        'type' => 'object',
        'required' => ['city'],
    ];

    expect(schemaProperties(SchemaCollections::class))->toBe([
        'tags' => ['items' => ['type' => 'string'], 'type' => 'array'],
        'scores' => ['items' => ['type' => 'integer'], 'type' => 'array'],
        'counts' => ['description' => 'Values: integer', 'type' => 'object'],
        'names' => ['items' => ['type' => 'string'], 'type' => 'array'],
        'price' => [
            'properties' => [
                'input' => ['type' => 'number'],
                'output' => ['type' => ['number', 'null']],
            ],
            'type' => 'object',
            'required' => ['input'],
        ],
        'addresses' => ['items' => $address, 'type' => 'array'],
        'extra' => ['type' => 'array'],
        'home' => $address,
        'mailing' => [...$address, 'description' => 'Where to send mail.', 'type' => ['object', 'null']],
    ]);
});

it('marks required, nullable and default values', function () {
    $object = ClassSchema::object(SchemaPresence::class)->toArray();

    expect($object['required'])->toBe(['required', 'present'])
        ->and($object['properties'])->toBe([
            'required' => ['type' => 'string'],
            'nullable' => ['type' => ['string', 'null']],
            'present' => ['type' => ['string', 'null']],
            'defaulted' => ['default' => 'x', 'type' => 'string'],
            'optional' => ['default' => 'y', 'type' => 'string'],
            'missing' => ['default' => 0, 'type' => 'integer'],
            'color' => ['enum' => ['red', 'green'], 'type' => ['string', 'null']],
            'tint' => ['default' => 'green', 'enum' => ['red', 'green'], 'type' => 'string'],
            'size' => ['default' => 'Large', 'enum' => ['Small', 'Large'], 'type' => 'string'],
            'ids' => ['default' => [1, 2], 'items' => ['type' => 'integer'], 'type' => 'array'],
            'counts' => ['description' => 'Values: integer', 'type' => 'object'],
            'ratio' => ['default' => 0.5, 'type' => 'number'],
        ]);
});

it('merges dotted keys under one parent into one object', function () {
    $object = ClassSchema::object(SchemaDottedKeys::class)->toArray();

    expect($object['required'])->toBe(['error', 'created.at'])
        ->and($object['properties'])->toBe([
            'error' => [
                'properties' => [
                    'message' => ['description' => 'What went wrong.', 'type' => 'string'],
                    'code' => ['default' => 0, 'type' => 'integer'],
                ],
                'type' => 'object',
                'required' => ['message'],
            ],
            'created.at' => ['type' => 'string'],
            'meta' => [
                'properties' => [
                    'trace' => [
                        'properties' => ['id' => ['type' => ['string', 'null']]],
                        'type' => 'object',
                    ],
                ],
                'type' => 'object',
            ],
        ]);
});

it('maps a discriminated union to anyOf with the discriminator in each member', function () {
    $card = fn (string $key) => [
        'properties' => [
            $key => ['enum' => ['Card'], 'type' => 'string'],
            'last4' => ['type' => 'string'],
            'saved' => ['default' => false, 'type' => 'boolean'],
        ],
        'type' => 'object',
        'required' => [$key, 'last4'],
    ];
    $bank = [
        'properties' => [
            'type' => ['enum' => ['bank'], 'type' => 'string'],
            'iban' => ['type' => 'string'],
        ],
        'type' => 'object',
        'required' => ['type', 'iban'],
    ];
    $voucher = [
        'properties' => [
            'kind' => ['enum' => ['Voucher'], 'type' => 'string'],
            'code' => ['type' => 'string'],
        ],
        'type' => 'object',
        'required' => ['kind', 'code'],
    ];

    expect(schemaProperties(SchemaUnions::class))->toBe([
        'payment' => ['anyOf' => [$card('type'), $bank]],
        'refund' => ['anyOf' => [$card('kind'), $voucher, ['type' => 'null']]],
        'fallbacks' => ['default' => [], 'items' => ['anyOf' => [$card('type'), $bank]], 'type' => 'array'],
    ]);
});

it('adds keywords for the rules that have one and skips the rest', function () {
    $object = ClassSchema::object(SchemaRules::class)->toArray();

    expect($object['properties'])->toBe([
        'name' => ['minLength' => 2, 'maxLength' => 10, 'type' => 'string'],
        'rating' => ['minimum' => 1, 'maximum' => 5, 'type' => 'integer'],
        'weight' => ['minimum' => 0.5, 'maximum' => 99.5, 'type' => 'number'],
        'tags' => ['minItems' => 1, 'maxItems' => 3, 'items' => ['type' => 'string'], 'type' => 'array'],
        'code' => ['minLength' => 8, 'maxLength' => 8, 'type' => 'string'],
        'status' => ['enum' => ['draft', 'published'], 'type' => 'string'],
        'level' => ['enum' => [1, 2, 3], 'type' => 'integer'],
        'email' => ['format' => 'email', 'type' => 'string'],
        'site' => ['format' => 'uri', 'type' => 'string'],
        'id' => ['format' => 'uuid', 'type' => 'string'],
        'date' => ['format' => 'date-time', 'type' => 'string'],
        'day' => ['format' => 'date', 'type' => 'string'],
        'slug' => ['pattern' => '^[a-z]+/\d+$', 'type' => 'string'],
        'hashtag' => ['pattern' => '^#\w+$', 'type' => 'string'],
        'caseless' => ['type' => 'string'],
        'handle' => ['type' => 'string'],
        'letter' => ['enum' => ['a', 'b'], 'type' => 'string'],
        'nickname' => ['default' => '', 'type' => 'string'],
    ])->and($object['required'])->toContain('nickname');
});

it('throws for a class that contains itself', function (string $class) {
    expect(fn () => ClassSchema::properties($class))
        ->toThrow(RuntimeException::class, 'contains itself');
})->with([Comment::class, Thread::class]);

readonly class SchemaFileMap
{
    /**
     * @param  array<string, UploadedFile>  $uploads
     */
    public function __construct(public array $uploads) {}
}

it('throws for a file upload', function (string $class) {
    expect(fn () => ClassSchema::object($class))->toThrow(
        RuntimeException::class,
        "Roma can't build a JSON Schema for {$class}: it contains a file upload. A schema describes MCP or AI tool input, "
        ."whose arguments are JSON, and JSON can't carry an uploaded file.",
    );
})->with([SchemaFile::class, SchemaFileMap::class]);

readonly class SchemaMaps
{
    /**
     * @param  array<string, SchemaAddress>  $addresses
     * @param  array<string, float>  $prices
     * @param  array<string, list<int>>  $series
     * @param  array<string, array<string, bool>>  $flags
     * @param  array<string, SchemaColor>  $colors
     * @param  array<string, DateTimeImmutable>  $dates
     * @param  array<string, array{a: int}>  $shapes
     * @param  array<string, PaymentMethod>  $methods
     * @param  array<string, Card|Voucher>  $refunds
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public array $addresses,
        #[Description('Prices by date.')]
        public array $prices,
        public array $series,
        public array $flags,
        public array $colors,
        public array $dates,
        public array $shapes,
        public array $methods,
        #[Discriminated([Card::class, Voucher::class])]
        public array $refunds,
        public array $extra,
    ) {}
}

it('describes the value type of a map', function () {
    $descriptions = array_map(fn (array $schema) => $schema['description'] ?? null, schemaProperties(SchemaMaps::class));

    expect($descriptions)->toBe([
        'addresses' => 'Values: SchemaAddress',
        'prices' => 'Prices by date. Values: number',
        'series' => 'Values: array of integer',
        'flags' => 'Values: map of boolean',
        'colors' => 'Values: SchemaColor',
        'dates' => 'Values: date',
        'shapes' => 'Values: object',
        'methods' => 'Values: PaymentMethod',
        'refunds' => 'Values: Card | Voucher',
        'extra' => null,
    ]);
});

it('rejects an HTTP source', function () {
    expect(fn () => ClassSchema::object(SchemaFromQuery::class))
        ->toThrow(RuntimeException::class, 'ArrayPolicy does not allow');
});

it('serializes through Laravel to the expected JSON Schema', function () {
    $object = ClassSchema::object(SchemaAddress::class);

    expect(json_decode((string) $object, true))->toBe([
        'description' => 'A postal address.',
        'properties' => [
            'city' => ['type' => 'string'],
            'postcode' => ['type' => ['string', 'null']],
        ],
        'type' => 'object',
        'required' => ['city'],
    ])->and(JsonSchema::object(ClassSchema::properties(SchemaAddress::class))->toArray())->toBe([
        'properties' => [
            'city' => ['type' => 'string'],
            'postcode' => ['type' => ['string', 'null']],
        ],
        'type' => 'object',
        'required' => ['city'],
    ]);
});

readonly class SchemaMissingTyped
{
    public function __construct(
        public string $name,
        public string|Missing $bio,
        public int|Missing|null $age,
    ) {}
}

it('leaves a type with Missing out of the required list', function () {
    $object = ClassSchema::object(SchemaMissingTyped::class)->toArray();

    expect($object['required'])->toBe(['name'])
        ->and($object['properties'])->toBe([
            'name' => ['type' => 'string'],
            'bio' => ['type' => 'string'],
            'age' => ['type' => ['integer', 'null']],
        ]);
});

readonly class SchemaDatedEvent
{
    public function __construct(public DateTimeImmutable $at) {}
}

readonly class SchemaDateFormatScope
{
    /**
     * @param  list<DateTimeImmutable>  $days
     * @param  array{day: DateTimeImmutable, event: SchemaDatedEvent}  $slot
     */
    public function __construct(
        #[DateFormat('Y-m-d')]
        public array $days,
        #[DateFormat('Y-m-d')]
        public array $slot,
    ) {}
}

it('applies #[DateFormat] to array elements but not to a nested object\'s dates', function () {
    $properties = ClassSchema::object(SchemaDateFormatScope::class)->toArray()['properties'];

    expect($properties['days']['items'])->toBe(['format' => 'date', 'type' => 'string'])
        ->and($properties['slot']['properties']['day'])->toBe(['format' => 'date', 'type' => 'string'])
        ->and($properties['slot']['properties']['event']['properties']['at'])->toBe(['format' => 'date-time', 'type' => 'string']);
});
