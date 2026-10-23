<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\IgnoreIf;
use BYanelli\Roma\Attributes\IgnoreIfEmpty;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Tests\TestCase;

readonly class IgnoredFilter
{
    public function __construct(
        #[IgnoreIfEmpty]
        #[Rule('min:3')]
        public string|Missing $term,
        #[IgnoreIfEmpty]
        public string|Missing|null $label,
    ) {}
}

readonly class IgnoredSettings
{
    /**
     * @param  list<string>|Missing  $tags
     * @param  list<IgnoredFilter>|Missing  $filters
     */
    public function __construct(
        #[IgnoreIfEmpty]
        public string|Missing|null $name,
        #[IgnoreIfEmpty]
        public array|Missing $tags,
        #[IgnoreIfEmpty]
        public int|Missing $count,
        #[IgnoreIfEmpty]
        public bool|Missing $active,
        #[IgnoreIfEmpty]
        public IgnoredFilter|Missing|null $filter,
        public array|Missing $filters,
        #[IgnoreIf(self::isPlaceholder(...))]
        #[IgnoreIf(static function (mixed $value): bool {
            return $value === 'none';
        })]
        public int|Missing $limit,
        #[IgnoreIf(self::isString(...))]
        public int|Missing $page,
    ) {}

    public static function isPlaceholder(mixed $value): bool
    {
        return $value === 'n/a';
    }

    public static function isString(mixed $value): bool
    {
        return is_string($value);
    }
}

readonly class IgnoreWithoutMissing
{
    public function __construct(
        #[IgnoreIfEmpty]
        public ?string $name = null,
    ) {}
}

readonly class IgnoreIfWithoutMissing
{
    public function __construct(
        #[IgnoreIf(IgnoredSettings::isPlaceholder(...))]
        public int $limit = 0,
    ) {}
}

/**
 * @return array<string, list<string>>
 */
function ignoredErrors(array $data): array
{
    try {
        Mapper::fromArray(IgnoredSettings::class, $data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a MappingValidationException.');
}

it('treats an empty string or array as absent with #[IgnoreIfEmpty]', function () {
    $settings = Mapper::fromArray(IgnoredSettings::class, ['name' => '', 'tags' => [], 'filter' => []]);

    expect($settings->name)->toBe(Missing::instance())
        ->and($settings->tags)->toBe(Missing::instance())
        ->and($settings->filter)->toBe(Missing::instance())
        ->and(Serializer::toArray($settings))->toBe([]);
});

it('keeps null, "0", 0 and false with #[IgnoreIfEmpty]', function () {
    $settings = Mapper::fromArray(IgnoredSettings::class, ['name' => '0', 'count' => 0, 'active' => false, 'filter' => null]);

    expect($settings->name)->toBe('0')
        ->and($settings->count)->toBe(0)
        ->and($settings->active)->toBeFalse()
        ->and($settings->filter)->toBeNull()
        ->and(Mapper::fromArray(IgnoredSettings::class, ['name' => null])->name)->toBeNull();
});

it('does not validate an ignored value', function () {
    expect(Mapper::fromArray(IgnoredSettings::class, ['filter' => ['term' => '']])->filter)
        ->toEqual(new IgnoredFilter(Missing::instance(), Missing::instance()))
        ->and(ignoredErrors(['count' => '', 'filter' => ['term' => 'ab']]))
        ->toBe(['data.filter.term' => ['The data.filter.term field must be at least 3 characters.']]);
});

it('ignores values in the objects of a list', function () {
    $settings = Mapper::fromArray(IgnoredSettings::class, ['filters' => [['term' => 'abc', 'label' => ''], ['term' => '']]]);

    expect($settings->filters)->toEqual([new IgnoredFilter('abc', Missing::instance()), new IgnoredFilter(Missing::instance(), Missing::instance())]);
});

it('treats a value as absent when an #[IgnoreIf] callable returns true for it', function () {
    expect(Mapper::fromArray(IgnoredSettings::class, ['limit' => 'n/a'])->limit)->toBe(Missing::instance())
        ->and(Mapper::fromArray(IgnoredSettings::class, ['limit' => 'none'])->limit)->toBe(Missing::instance())
        ->and(Mapper::fromArray(IgnoredSettings::class, ['limit' => '5'])->limit)->toBe(5)
        ->and(ignoredErrors(['limit' => 'many']))->toBe(['data.limit' => ['The data.limit field must be an integer.']]);
});

it('passes the raw value to the callable, before coercion', function () {
    expect(Mapper::fromArray(IgnoredSettings::class, ['page' => '7'])->page)->toBe(Missing::instance())
        ->and(Mapper::fromArray(IgnoredSettings::class, ['page' => 7])->page)->toBe(7);
});

it('ignores an empty object in a request body', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['filter' => (object) [], 'name' => 'x']);

    expect($this->mapRequest(IgnoredSettings::class)->filter)->toBe(Missing::instance());
});

it('rejects an ignore attribute on a property without Missing', function (string $class, string $attribute) {
    expect(fn () => Mapper::fromArray($class, []))->toThrow(
        RuntimeException::class,
        "Roma property \"{$class}::\$".($attribute === 'IgnoreIfEmpty' ? 'name' : 'limit')."\" has #[{$attribute}], which treats a value as absent, but its type has no Missing.",
    );
})->with([
    [IgnoreWithoutMissing::class, 'IgnoreIfEmpty'],
    [IgnoreIfWithoutMissing::class, 'IgnoreIf'],
]);
