<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Delimited;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

enum EmptyStringStatus: string
{
    case Active = 'active';
}

readonly class EmptyStringMember
{
    public function __construct(public ?int $count = null) {}
}

readonly class EmptyStringFields
{
    /**
     * @param  ?list<int>  $ids
     * @param  list<int>  $numbers
     * @param  array{limit?: int}|null  $shape
     * @param  list<string>  $tags
     * @param  ?list<string>  $labels
     */
    public function __construct(
        public ?int $limit = null,
        public int $page = 1,
        public ?bool $active = null,
        public ?float $price = null,
        public ?EmptyStringStatus $status = null,
        public ?array $ids = null,
        public array $numbers = [],
        public ?array $shape = null,
        public ?DateTimeImmutable $at = null,
        public ?EmptyStringMember $member = null,
        public string $name = 'default',
        public ?string $note = null,
        #[Delimited]
        public array $tags = ['default'],
        #[Delimited]
        public ?array $labels = null,
    ) {}
}

readonly class EmptyStringRequired
{
    public function __construct(public int $count) {}
}

/**
 * @return array<string, list<string>>
 */
function emptyStringErrors(string $class, array $data): array
{
    try {
        Mapper::fromArray($class, $data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a MappingValidationException.');
}

it('reads an empty string as null for a type with no string', function (string $key, mixed $expected) {
    expect(Mapper::fromArray(EmptyStringFields::class, [$key => ''])->{$key})->toBe($expected);
})->with([
    '?int' => ['limit', null],
    'int with a default' => ['page', 1],
    '?bool' => ['active', null],
    '?float' => ['price', null],
    'a nullable enum' => ['status', null],
    '?array' => ['ids', null],
    'an array with a default' => ['numbers', []],
    'a shape' => ['shape', null],
    'a date' => ['at', null],
    'a nested object' => ['member', null],
]);

// "" becomes null before #[Delimited] splits it, as it would with ConvertEmptyStringsToNull.
it('reads an empty string as null for a #[Delimited] array', function () {
    $fields = Mapper::fromArray(EmptyStringFields::class, ['tags' => '', 'labels' => '']);

    expect($fields->tags)->toBe(['default'])
        ->and($fields->labels)->toBeNull();
});

it('keeps an empty string for a string', function () {
    $fields = Mapper::fromArray(EmptyStringFields::class, ['name' => '', 'note' => '']);

    expect($fields->name)->toBe('')
        ->and($fields->note)->toBe('');
});

it('reads empty strings as null in arrays, shapes and nested objects', function () {
    $fields = Mapper::fromArray(EmptyStringFields::class, ['member' => ['count' => '']]);

    expect($fields->member)->toEqual(new EmptyStringMember)
        ->and(emptyStringErrors(EmptyStringFields::class, ['shape' => ['limit' => '']]))
        ->toBe(emptyStringErrors(EmptyStringFields::class, ['shape' => ['limit' => null]]))
        ->and(emptyStringErrors(EmptyStringFields::class, ['numbers' => ['1', '']]))
        ->toBe(['data.numbers.1' => ['The data.numbers.1 field must be an integer.']]);
});

it('rejects an empty string for a required int', function () {
    expect(emptyStringErrors(EmptyStringRequired::class, ['count' => '']))
        ->toBe(['data.count' => ['The data.count field is required.']]);
});

it('reads an empty request value as null without ConvertEmptyStringsToNull', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['limit' => '', 'page' => '', 'name' => '']);

    $fields = $this->mapRequest(EmptyStringFields::class);

    expect($fields->limit)->toBeNull()
        ->and($fields->page)->toBe(1)
        ->and($fields->name)->toBe('');

    $this->setRequest(json: ['count' => '']);

    expect(fn () => $this->getRequestMapper()->mapBody(EmptyStringRequired::class))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['count' => ['The count field is required.']]));
});
