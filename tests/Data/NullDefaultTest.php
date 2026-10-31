<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Message;
use BYanelli\Roma\Attributes\RejectNull;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Http\Attributes\Body;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

enum NullDefaultSize: string
{
    case Small = 's';
    case Large = 'l';
}

readonly class NullDefaultRange
{
    public function __construct(public int $min = 0, public int $max = 10) {}
}

readonly class NullDefaults
{
    public function __construct(
        public bool $active = false,
        public string $label = 'none',
        public int $page = 1,
        public NullDefaultSize $size = NullDefaultSize::Small,
        public NullDefaultRange $range = new NullDefaultRange,
        public ?string $source = 'api',
        public ?string $note = null,
    ) {}
}

readonly class NullWithoutDefault
{
    public function __construct(public int $count) {}
}

/**
 * @return array<string, list<string>>
 */
function nullDefaultErrors(string $class, array $data): array
{
    try {
        Mapper::fromArray($class, $data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected MappingValidationException');
}

it('gives a null the default of a property that is not nullable', function () {
    $nulls = ['active' => null, 'label' => null, 'page' => null, 'size' => null, 'range' => null];

    expect(Mapper::fromArray(NullDefaults::class, $nulls))->toEqual(new NullDefaults);
});

it('gives an absent value the default', function () {
    expect(Mapper::fromArray(NullDefaults::class, []))->toEqual(new NullDefaults);
});

it('gives a null member of a nested object its default', function () {
    expect(Mapper::fromArray(NullDefaults::class, ['range' => ['min' => null, 'max' => 5]])->range)
        ->toEqual(new NullDefaultRange(0, 5));
});

it('keeps a null for a nullable property', function () {
    $object = Mapper::fromArray(NullDefaults::class, ['source' => null, 'note' => null]);

    expect($object->source)->toBeNull()
        ->and($object->note)->toBeNull();
});

it('still requires a property without a default', function () {
    expect(nullDefaultErrors(NullWithoutDefault::class, ['count' => null]))
        ->toBe(['data.count' => ['The data.count field is required.']]);
});

readonly class NullWithoutDefaultParent
{
    /**
     * @param  list<NullWithoutDefault>  $children
     */
    public function __construct(public NullWithoutDefault $child, public array $children = []) {}
}

it('still requires a member without a default of a nested object', function () {
    expect(nullDefaultErrors(NullWithoutDefaultParent::class, ['child' => ['count' => null], 'children' => [['count' => null]]]))
        ->toBe([
            'data.child.count' => ['The data.child.count field is required.'],
            'data.children.0.count' => ['The data.children.0.count field is required.'],
        ])
        ->and(nullDefaultErrors(NullWithoutDefaultParent::class, ['child' => ['count' => 'x'], 'children' => [['count' => 'x']]]))
        ->toBe([
            'data.child.count' => ['The data.child.count field must be an integer.'],
            'data.children.0.count' => ['The data.children.0.count field must be an integer.'],
        ]);
});

readonly class NullDefaultSources
{
    public function __construct(
        #[Query]
        public int $page = 1,
        #[Body]
        public bool $notify = true,
        #[Header('X-Mode')]
        public string $mode = 'fast',
    ) {}
}

it('gives a null the default in any source', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['page' => null], headers: ['Content-Type' => 'application/json', 'X-Mode' => null], json: ['notify' => null]);

    expect($this->mapRequest(NullDefaultSources::class))->toEqual(new NullDefaultSources);
});

readonly class NullRejected
{
    public function __construct(
        #[RejectNull]
        public bool $active = false,
        #[RejectNull]
        #[Message(string: 'Send a label or leave it out.')]
        public string $label = 'none',
        public int $page = 1,
    ) {}
}

#[RejectNull]
readonly class NullRejectedClass
{
    public function __construct(
        public int $page = 1,
        public ?string $source = 'api',
        public int $count = 0,
    ) {}
}

readonly class NullRejectedChild extends NullRejectedClass {}

it('rejects a null with #[RejectNull] on the property', function () {
    expect(nullDefaultErrors(NullRejected::class, ['active' => null, 'label' => null, 'page' => null]))->toBe([
        'data.active' => ['The data.active field must be true or false.'],
        'data.label' => ['Send a label or leave it out.'],
    ]);
});

it('rejects a null with #[RejectNull] on the class or a parent', function (string $class) {
    expect(nullDefaultErrors($class, ['page' => null, 'source' => null]))
        ->toBe(['data.page' => ['The data.page field must be an integer.']])
        ->and(Mapper::fromArray($class, ['source' => null])->source)->toBeNull();
})->with([NullRejectedClass::class, NullRejectedChild::class]);

it('rejects a null in a request with #[RejectNull]', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['active' => null]);

    expect(fn () => $this->mapRequest(NullRejected::class))->toThrow(ValidationException::class);
});

readonly class RejectNullOnNullable
{
    public function __construct(
        #[RejectNull]
        public ?string $note = 'x',
    ) {}
}

readonly class RejectNullWithoutDefault
{
    public function __construct(
        #[RejectNull]
        public string $note,
    ) {}
}

it('rejects #[RejectNull] where it has no effect', function (string $class, string $message) {
    expect(fn () => Mapper::fromArray($class, []))->toThrow(RuntimeException::class, $message);
})->with([
    [RejectNullOnNullable::class, 'Its type allows null.'],
    [RejectNullWithoutDefault::class, 'It has no default, so null already fails validation.'],
]);
