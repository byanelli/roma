<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\DateFormat;
use BYanelli\Roma\Attributes\Delimited;
use BYanelli\Roma\Attributes\IgnoreIfEmpty;
use BYanelli\Roma\Attributes\Message;
use BYanelli\Roma\Attributes\OptionalOnUpdate;
use BYanelli\Roma\Attributes\Present;
use BYanelli\Roma\Attributes\RejectNumbers;
use BYanelli\Roma\Attributes\RequiredOnCreate;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Tests\Support\Discriminated\Card;
use BYanelli\Roma\Tests\Support\Discriminated\PaymentMethod;
use BYanelli\Roma\Tests\Support\Recursive\Entry;
use BYanelli\Roma\Tests\Support\Recursive\Folder;
use BYanelli\Roma\Values\ByteSize;

readonly class EmptyDefaults
{
    public function __construct(
        public string $color = 'gray',
        public ?int $size = null,
    ) {}
}

readonly class EmptyNoProperties {}

readonly class EmptyAddress
{
    public function __construct(public string $city) {}
}

readonly class EmptyAllMissing
{
    public function __construct(
        public string|Missing $a,
        public int|Missing $b,
    ) {}
}

readonly class EmptyListHolder
{
    /** @param list<string> $v */
    public function __construct(public array $v) {}
}

readonly class EmptyArrayHolder
{
    /** @param array<string> $v */
    public function __construct(public array $v) {}
}

readonly class EmptyStringMapHolder
{
    /** @param array<string, int> $v */
    public function __construct(public array $v) {}
}

readonly class EmptyIntMapHolder
{
    /** @param array<int, string> $v */
    public function __construct(public array $v) {}
}

readonly class EmptyOptionalShapeHolder
{
    /** @param array{a?: int} $v */
    public function __construct(public array $v) {}
}

readonly class EmptyDefaultsHolder
{
    public function __construct(public EmptyDefaults $v) {}
}

readonly class EmptyNoPropertiesHolder
{
    public function __construct(public EmptyNoProperties $v) {}
}

readonly class EmptyAddressHolder
{
    public function __construct(public EmptyAddress $address) {}
}

readonly class EmptyRequiredShapeHolder
{
    /** @param array{a: int} $v */
    public function __construct(public array $v) {}
}

readonly class EmptyUnionHolder
{
    public function __construct(public PaymentMethod $method) {}
}

readonly class EmptyUnionListHolder
{
    /** @param list<PaymentMethod> $methods */
    public function __construct(public array $methods) {}
}

readonly class EmptyPropertyHolder
{
    /** @var list<string> */
    public array $v;
}

readonly class EmptyMessageHolder
{
    /** @param list<string> $v */
    public function __construct(
        #[Message(required: 'Send the list.')]
        public array $v,
    ) {}
}

readonly class EmptyScalarHolder
{
    public function __construct(
        public string $name,
        public int $count,
    ) {}
}

readonly class EmptyByteSizeHolder
{
    public function __construct(public ByteSize $v) {}
}

readonly class EmptyMember
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public array $tags,
        public EmptyDefaults $options,
    ) {}
}

readonly class EmptyMemberParent
{
    /**
     * @param  list<EmptyMember>  $items
     * @param  array{tags: list<string>, options: EmptyDefaults, note: ?list<string>}  $shape
     */
    public function __construct(
        public ?EmptyMember $member = null,
        public array $items = [],
        public array $shape = ['tags' => [], 'options' => new EmptyDefaults, 'note' => null],
    ) {}
}

function emptyErrors(string $class, array $data, Operation $operation = Operation::None): array
{
    try {
        Mapper::fromArray($class, $data, $operation);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    return [];
}

function emptyObjectErrors(object $object, Operation $operation = Operation::None): array
{
    try {
        Mapper::validate($object, $operation);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    return [];
}

dataset('array-like required properties', [
    'list<T>' => [EmptyListHolder::class],
    'array<T>' => [EmptyArrayHolder::class],
    'array<string, T>' => [EmptyStringMapHolder::class],
    'array<int, T>' => [EmptyIntMapHolder::class],
    'shape with optional keys' => [EmptyOptionalShapeHolder::class],
    'class with defaults' => [EmptyDefaultsHolder::class],
    'class with no properties' => [EmptyNoPropertiesHolder::class],
    'class property' => [EmptyPropertyHolder::class],
]);

describe('a required array-like property', function () {
    it('accepts an empty value', function (string $class) {
        expect(emptyErrors($class, ['v' => []]))->toBe([]);
    })->with('array-like required properties');

    it('fails with Laravel\'s message when the key is absent or null', function (string $class) {
        $errors = ['data.v' => ['The data.v field is required.']];

        expect(emptyErrors($class, []))->toBe($errors)
            ->and(emptyErrors($class, ['v' => null]))->toBe($errors);
    })->with('array-like required properties');

    it('maps an empty value to an empty array or an object', function () {
        expect(Mapper::fromArray(EmptyListHolder::class, ['v' => []])->v)->toBe([])
            ->and(Mapper::fromArray(EmptyStringMapHolder::class, ['v' => []])->v)->toBe([])
            ->and(Mapper::fromArray(EmptyOptionalShapeHolder::class, ['v' => []])->v)->toBe([])
            ->and(Mapper::fromArray(EmptyDefaultsHolder::class, ['v' => []])->v)->toEqual(new EmptyDefaults)
            ->and(Mapper::fromArray(EmptyNoPropertiesHolder::class, ['v' => []])->v)->toEqual(new EmptyNoProperties)
            ->and(Mapper::fromArray(EmptyUnionListHolder::class, ['methods' => []])->methods)->toBe([]);
    });

    it('fails for a blank string, which the array rule skips', function (string $class) {
        expect(emptyErrors($class, ['v' => '   ']))->toBe(['data.v' => ['The data.v field is required.']])
            ->and(emptyErrors($class, ['v' => '']))->toBe(['data.v' => ['The data.v field is required.']]);
    })->with('array-like required properties');

    it('reports only the type\'s rule for a value of the wrong type', function () {
        expect(emptyErrors(EmptyArrayHolder::class, ['v' => 'x']))->toBe(['data.v' => ['The data.v field must be an array.']])
            ->and(emptyErrors(EmptyArrayHolder::class, ['v' => 0]))->toBe(['data.v' => ['The data.v field must be an array.']])
            ->and(emptyErrors(EmptyArrayHolder::class, ['v' => false]))->toBe(['data.v' => ['The data.v field must be an array.']]);
    });

    it('uses #[Message] for the required rule', function () {
        $errors = ['data.v' => ['Send the list.']];

        expect(emptyErrors(EmptyMessageHolder::class, []))->toBe($errors)
            ->and(emptyErrors(EmptyMessageHolder::class, ['v' => null]))->toBe($errors)
            ->and(emptyErrors(EmptyMessageHolder::class, ['v' => []]))->toBe([]);
    });

    it('leaves a class\'s required members to report their own errors', function () {
        expect(emptyErrors(EmptyAddressHolder::class, ['address' => []]))
            ->toBe(['data.address.city' => ['The data.address.city field is required.']])
            ->and(emptyErrors(EmptyRequiredShapeHolder::class, ['v' => []]))
            ->toBe(['data.v.a' => ['The data.v.a field is required.']]);
    });

    it('fails a union given {} on its discriminator', function () {
        expect(emptyErrors(EmptyUnionHolder::class, ['method' => []]))
            ->toBe(['data.method.type' => ['The data.method.type field must be one of: Card, bank.']]);
    });

    it('still reports the union property when it is absent or null', function () {
        $errors = ['data.method' => ['The data.method field is required.']];

        expect(emptyErrors(EmptyUnionHolder::class, []))->toBe($errors)
            ->and(emptyErrors(EmptyUnionHolder::class, ['method' => null]))->toBe($errors);
    });

    it('terminates on a recursive class', function () {
        expect(Mapper::fromArray(EmptyRecursiveHolder::class, ['children' => []])->children)->toBe([])
            ->and(emptyErrors(EmptyRecursiveHolder::class, ['children' => [['name' => 'a']]]))
            ->toBe(['data.children.0.children' => ['The data.children.0.children field is required.']])
            ->and(Mapper::fromArray(EmptyRecursiveHolder::class, ['children' => [['name' => 'a', 'children' => []]]])->children[0]->name)
            ->toBe('a');
    });

    it('accepts an empty list in a union member of a recursive type', function () {
        $entry = Mapper::fromArray(EmptyEntryHolder::class, ['entry' => ['type' => 'Folder', 'name' => 'root', 'children' => []]])->entry;

        expect($entry)->toEqual(new Folder('root', []));
    });
});

readonly class EmptyRecursiveHolder
{
    /**
     * @param  list<EmptyRecursiveHolder>  $children
     */
    public function __construct(
        public array $children,
        public string $name = 'root',
    ) {}
}

readonly class EmptyEntryHolder
{
    public function __construct(public Entry $entry) {}
}

describe('unchanged behavior', function () {
    it('still rejects an empty string for a required scalar', function () {
        expect(emptyErrors(EmptyScalarHolder::class, ['name' => '', 'count' => 1]))
            ->toBe(['data.name' => ['The data.name field is required.']]);
    });

    it('still rejects [] for a value object validated as a string', function () {
        expect(emptyErrors(EmptyByteSizeHolder::class, ['v' => []]))
            ->toBe(['data.v' => ['The data.v field is required.']]);
    });

    it('still treats [] under #[IgnoreIfEmpty] as absent', function () {
        expect(Mapper::fromArray(EmptyIgnoredHolder::class, ['v' => [], 'nonEmpty' => []]))
            ->v->toBeInstanceOf(Missing::class)
            ->nonEmpty->toBeInstanceOf(Missing::class);
    });
});

readonly class EmptyIgnoredHolder
{
    /**
     * @param  list<string>|Missing  $v
     * @param  non-empty-list<string>|Missing  $nonEmpty
     */
    public function __construct(
        #[IgnoreIfEmpty]
        public array|Missing $v,

        #[IgnoreIfEmpty]
        public array|Missing $nonEmpty,
    ) {}
}

readonly class EmptyAnyMessageHolder
{
    /** @param list<string> $v */
    public function __construct(
        #[Message('Send a list.')]
        public array $v,
    ) {}
}

it('uses a #[Message] without a rule name for the required rule', function () {
    expect(emptyErrors(EmptyAnyMessageHolder::class, []))->toBe(['data.v' => ['Send a list.']]);
});

describe('a nested member', function () {
    it('accepts an empty value inside a present object', function () {
        expect(emptyErrors(EmptyMemberParent::class, ['member' => ['tags' => [], 'options' => []]]))->toBe([]);
    });

    it('fails when absent or null inside a present object', function () {
        expect(emptyErrors(EmptyMemberParent::class, ['member' => []]))->toBe([
            'data.member.tags' => ['The data.member.tags field is required.'],
            'data.member.options' => ['The data.member.options field is required.'],
        ])->and(emptyErrors(EmptyMemberParent::class, ['member' => ['tags' => null, 'options' => null]]))->toBe([
            'data.member.tags' => ['The data.member.tags field is required.'],
            'data.member.options' => ['The data.member.options field is required.'],
        ]);
    });

    it('fails for a blank string', function () {
        expect(emptyErrors(EmptyMemberParent::class, ['member' => ['tags' => '  ', 'options' => []]]))
            ->toBe(['data.member.tags' => ['The data.member.tags field is required.']]);
    });

    it('requires nothing when the object is absent or null', function () {
        expect(emptyErrors(EmptyMemberParent::class, []))->toBe([])
            ->and(emptyErrors(EmptyMemberParent::class, ['member' => null]))->toBe([]);
    });

    it('applies to the items of a list', function () {
        expect(emptyErrors(EmptyMemberParent::class, ['items' => [['tags' => [], 'options' => []], []]]))->toBe([
            'data.items.1.tags' => ['The data.items.1.tags field is required.'],
            'data.items.1.options' => ['The data.items.1.options field is required.'],
        ]);
    });

    it('applies to the items of a shape', function () {
        expect(emptyErrors(EmptyMemberParent::class, ['shape' => ['tags' => [], 'options' => [], 'note' => []]]))->toBe([])
            ->and(emptyErrors(EmptyMemberParent::class, ['shape' => ['tags' => [], 'options' => [], 'note' => null]]))->toBe([])
            ->and(emptyErrors(EmptyMemberParent::class, ['shape' => ['tags' => null, 'options' => []]]))->toBe([
                'data.shape.tags' => ['The data.shape.tags field is required.'],
                'data.shape.note' => ['The data.shape.note field is required.'],
            ]);
    });
});

readonly class NonEmptyListHolder
{
    /** @param non-empty-list<string> $v */
    public function __construct(public array $v) {}
}

readonly class NonEmptyArrayHolder
{
    /** @param non-empty-array<string> $v */
    public function __construct(public array $v) {}
}

readonly class NonEmptyMapHolder
{
    /** @param non-empty-array<string, int> $v */
    public function __construct(public array $v) {}
}

readonly class NonEmptyShapeHolder
{
    /** @param non-empty-array{a?: int} $v */
    public function __construct(public array $v) {}
}

readonly class NonEmptyDefaultHolder
{
    /** @param non-empty-list<string> $v */
    public function __construct(public array $v = ['a']) {}
}

readonly class NonEmptyNullableHolder
{
    /** @param ?non-empty-list<string> $v */
    public function __construct(public ?array $v = null) {}
}

readonly class NonEmptyMissingHolder
{
    /** @param non-empty-array<string, int>|Missing $v */
    public function __construct(public array|Missing $v) {}
}

readonly class NonEmptyMessageHolder
{
    /**
     * @param  non-empty-list<string>  $required
     * @param  ?non-empty-list<string>  $optional
     */
    public function __construct(
        #[Message(required: 'Send at least one.')]
        public array $required,

        #[Message(non_empty: 'Send one or leave it out.')]
        public ?array $optional = null,
    ) {}
}

readonly class NonEmptyMember
{
    /**
     * @param  non-empty-list<string>  $required
     * @param  ?non-empty-list<string>  $optional
     */
    public function __construct(
        public array $required,
        public ?array $optional = null,
    ) {}
}

readonly class NonEmptyMemberParent
{
    /**
     * @param  list<NonEmptyMember>  $items
     * @param  list<non-empty-list<int>>  $lists
     * @param  array{required: non-empty-list<int>, optional?: non-empty-list<int>}  $shape
     */
    public function __construct(
        public ?NonEmptyMember $member = null,
        public array $items = [],
        public array $lists = [],
        public array $shape = ['required' => [1]],
    ) {}
}

readonly class NonEmptyAttributeHolder
{
    /**
     * @param  non-empty-list<string>  $strings
     * @param  non-empty-list<DateTimeImmutable>  $dates
     */
    public function __construct(
        #[RejectNumbers]
        public array $strings = ['a'],

        #[DateFormat('Y-m-d')]
        public array $dates = [new DateTimeImmutable('2026-01-01')],
    ) {}
}

readonly class NonEmptyDelimitedHolder
{
    /** @param non-empty-list<string> $v */
    public function __construct(
        #[Delimited]
        public array $v,
    ) {}
}

readonly class NonEmptyOperationHolder
{
    /**
     * @param  non-empty-list<string>  $onCreate
     * @param  non-empty-list<string>  $exceptUpdate
     */
    public function __construct(
        #[RequiredOnCreate]
        public array|Missing $onCreate,

        #[OptionalOnUpdate]
        public array|Missing $exceptUpdate,
    ) {}
}

readonly class EmptyOperationHolder
{
    /**
     * @param  list<string>  $onCreate
     * @param  list<string>  $exceptUpdate
     */
    public function __construct(
        #[RequiredOnCreate]
        public array|Missing $onCreate,

        #[OptionalOnUpdate]
        public array|Missing $exceptUpdate,
    ) {}
}

describe('non-empty-list and non-empty-array', function () {
    it('rejects [] for a required property with the required message', function (string $class) {
        expect(emptyErrors($class, ['v' => []]))->toBe(['data.v' => ['The data.v field is required.']])
            ->and(emptyErrors($class, []))->toBe(['data.v' => ['The data.v field is required.']]);
    })->with([
        'non-empty-list<T>' => [NonEmptyListHolder::class],
        'non-empty-array<T>' => [NonEmptyArrayHolder::class],
        'non-empty-array<K, T>' => [NonEmptyMapHolder::class],
        'non-empty-array{...}' => [NonEmptyShapeHolder::class],
    ]);

    it('accepts a value with items', function () {
        expect(Mapper::fromArray(NonEmptyListHolder::class, ['v' => ['a']])->v)->toBe(['a'])
            ->and(Mapper::fromArray(NonEmptyMapHolder::class, ['v' => ['a' => 1]])->v)->toBe(['a' => 1])
            ->and(Mapper::fromArray(NonEmptyShapeHolder::class, ['v' => ['a' => 1]])->v)->toBe(['a' => 1]);
    });

    it('rejects [] on a property with a default', function () {
        expect(emptyErrors(NonEmptyDefaultHolder::class, ['v' => []]))->toBe(['data.v' => ['The data.v field must not be empty.']])
            ->and(Mapper::fromArray(NonEmptyDefaultHolder::class, [])->v)->toBe(['a']);
    });

    it('rejects [] on a nullable property and accepts null', function () {
        expect(emptyErrors(NonEmptyNullableHolder::class, ['v' => []]))->toBe(['data.v' => ['The data.v field must not be empty.']])
            ->and(Mapper::fromArray(NonEmptyNullableHolder::class, ['v' => null])->v)->toBeNull()
            ->and(Mapper::fromArray(NonEmptyNullableHolder::class, [])->v)->toBeNull();
    });

    it('rejects [] on a Missing property only when it is given', function () {
        expect(emptyErrors(NonEmptyMissingHolder::class, ['v' => []]))->toBe(['data.v' => ['The data.v field must not be empty.']])
            ->and(Mapper::fromArray(NonEmptyMissingHolder::class, [])->v)->toBeInstanceOf(Missing::class);
    });

    it('applies #[Message] by rule name', function () {
        expect(emptyErrors(NonEmptyMessageHolder::class, ['required' => []]))->toBe(['data.required' => ['Send at least one.']])
            ->and(emptyErrors(NonEmptyMessageHolder::class, ['required' => ['a'], 'optional' => []]))->toBe(['data.optional' => ['Send one or leave it out.']]);
    });

    it('rejects [] for members of nested objects', function () {
        expect(emptyErrors(NonEmptyMemberParent::class, ['member' => ['required' => [], 'optional' => []]]))->toBe([
            'data.member.required' => ['The data.member.required field is required.'],
            'data.member.optional' => ['The data.member.optional field must not be empty.'],
        ])->and(emptyErrors(NonEmptyMemberParent::class, ['items' => [['required' => []]]]))->toBe([
            'data.items.0.required' => ['The data.items.0.required field is required.'],
        ]);
    });

    it('rejects [] for the items of a list', function () {
        expect(emptyErrors(NonEmptyMemberParent::class, ['lists' => [[1], []]]))
            ->toBe(['data.lists.1' => ['The data.lists.1 field must not be empty.']]);
    });

    it('rejects [] for the items of a shape', function () {
        expect(emptyErrors(NonEmptyMemberParent::class, ['shape' => ['required' => [], 'optional' => []]]))->toBe([
            'data.shape.required' => ['The data.shape.required field is required.'],
            'data.shape.optional' => ['The data.shape.optional field must not be empty.'],
        ]);
    });

    it('stays non-empty under #[RejectNumbers] and #[DateFormat]', function () {
        expect(emptyErrors(NonEmptyAttributeHolder::class, ['strings' => [], 'dates' => []]))->toBe([
            'data.strings' => ['The data.strings field must not be empty.'],
            'data.dates' => ['The data.dates field must not be empty.'],
        ]);
    });

    it('rejects a #[Delimited] string with no items', function () {
        expect(emptyErrors(NonEmptyDelimitedHolder::class, ['v' => ',']))->toBe(['data.v' => ['The data.v field is required.']]);
    });
});

describe('operations', function () {
    it('requires a #[RequiredOnCreate] array in a Create, where [] passes', function () {
        expect(emptyErrors(EmptyOperationHolder::class, [], Operation::Create))->toBe([
            'data.onCreate' => ['The data.onCreate field is required.'],
            'data.exceptUpdate' => ['The data.exceptUpdate field is required.'],
        ])->and(emptyErrors(EmptyOperationHolder::class, ['onCreate' => null, 'exceptUpdate' => null], Operation::Create))->toBe([
            'data.onCreate' => ['The data.onCreate field is required.'],
            'data.exceptUpdate' => ['The data.exceptUpdate field is required.'],
        ])->and(emptyErrors(EmptyOperationHolder::class, ['onCreate' => [], 'exceptUpdate' => []], Operation::Create))->toBe([]);
    });

    it('leaves them optional in an Update', function () {
        expect(emptyErrors(EmptyOperationHolder::class, [], Operation::Update))->toBe([])
            ->and(emptyErrors(EmptyOperationHolder::class, ['onCreate' => [], 'exceptUpdate' => []], Operation::Update))->toBe([]);
    });

    it('rejects [] for a non-empty list in all operations', function () {
        $required = [
            'data.onCreate' => ['The data.onCreate field is required.'],
            'data.exceptUpdate' => ['The data.exceptUpdate field is required.'],
        ];

        expect(emptyErrors(NonEmptyOperationHolder::class, ['onCreate' => [], 'exceptUpdate' => []], Operation::Create))->toBe($required)
            ->and(emptyErrors(NonEmptyOperationHolder::class, ['onCreate' => [], 'exceptUpdate' => []], Operation::Update))->toBe([
                'data.onCreate' => ['The data.onCreate field must not be empty.'],
                'data.exceptUpdate' => ['The data.exceptUpdate field must not be empty.'],
            ])
            ->and(emptyErrors(NonEmptyOperationHolder::class, [], Operation::Update))->toBe([]);
    });
});

readonly class PresentListHolder
{
    /** @param list<string> $v */
    public function __construct(
        #[Present]
        public array $v,
    ) {}
}

readonly class PresentNullableListHolder
{
    /** @param ?list<string> $v */
    public function __construct(
        #[Present]
        public ?array $v,
    ) {}
}

readonly class PresentMemberParent
{
    public function __construct(public PresentListHolder $member) {}
}

readonly class PresentNonEmptyNullableHolder
{
    /** @param ?non-empty-list<string> $v */
    public function __construct(
        #[Present]
        public ?array $v,
    ) {}
}

describe('#[Present]', function () {
    it('accepts [] on a non-nullable array and reports one message when it is absent or null', function () {
        $errors = ['data.v' => ['The data.v field is required.']];

        expect(emptyErrors(PresentListHolder::class, ['v' => []]))->toBe([])
            ->and(emptyErrors(PresentListHolder::class, []))->toBe($errors)
            ->and(emptyErrors(PresentListHolder::class, ['v' => null]))->toBe($errors);
    });

    it('does the same in a nested object', function () {
        expect(emptyErrors(PresentMemberParent::class, ['member' => ['v' => []]]))->toBe([])
            ->and(emptyErrors(PresentMemberParent::class, ['member' => []]))
            ->toBe(['data.member.v' => ['The data.member.v field is required.']]);
    });

    it('keeps its behavior on a nullable array', function () {
        expect(emptyErrors(PresentNullableListHolder::class, ['v' => []]))->toBe([])
            ->and(emptyErrors(PresentNullableListHolder::class, ['v' => null]))->toBe([])
            ->and(emptyErrors(PresentNullableListHolder::class, []))->toBe(['data.v' => ['The data.v field must be present.']]);
    });

    it('still rejects [] for a nullable non-empty list', function () {
        expect(emptyErrors(PresentNonEmptyNullableHolder::class, ['v' => []]))->toBe(['data.v' => ['The data.v field must not be empty.']])
            ->and(emptyErrors(PresentNonEmptyNullableHolder::class, ['v' => null]))->toBe([]);
    });
});

readonly class EmptyMissingMember
{
    public function __construct(public EmptyAllMissing $nested) {}
}

class EmptyMutableList
{
    /** @var list<string> */
    public array $v;
}

describe('Mapper::validate()', function () {
    it('passes [] for a list, a map and a shape', function () {
        expect(emptyObjectErrors(new EmptyListHolder([])))->toBe([])
            ->and(emptyObjectErrors(new EmptyStringMapHolder([])))->toBe([])
            ->and(emptyObjectErrors(new EmptyOptionalShapeHolder([])))->toBe([]);
    });

    it('passes a nested object whose properties are all Missing', function () {
        expect(emptyObjectErrors(new EmptyMissingMember(new EmptyAllMissing(Missing::instance(), Missing::instance()))))->toBe([]);
    });

    it('passes a nested class with no properties', function () {
        expect(emptyObjectErrors(new EmptyNoPropertiesHolder(new EmptyNoProperties)))->toBe([]);
    });

    it('passes a list of a union with no items, and a member with its discriminator', function () {
        expect(emptyObjectErrors(new EmptyUnionListHolder([])))->toBe([])
            ->and(emptyObjectErrors(new EmptyUnionHolder(new Card('1234'))))->toBe([]);
    });

    it('fails [] for a non-empty type', function () {
        expect(emptyObjectErrors(new NonEmptyListHolder([])))->toBe(['v' => ['The v field is required.']])
            ->and(emptyObjectErrors(new NonEmptyDefaultHolder([])))->toBe(['v' => ['The v field must not be empty.']])
            ->and(emptyObjectErrors(new NonEmptyNullableHolder([])))->toBe(['v' => ['The v field must not be empty.']])
            ->and(emptyObjectErrors(new NonEmptyMissingHolder([])))->toBe(['v' => ['The v field must not be empty.']])
            ->and(emptyObjectErrors(new NonEmptyMemberParent(new NonEmptyMember([], []))))->toBe([
                'member.required' => ['The member.required field is required.'],
                'member.optional' => ['The member.optional field must not be empty.'],
            ]);
    });

    it('still fails an unset property', function () {
        expect(emptyObjectErrors(new EmptyMutableList))->toBe(['v' => ['The v field is required.']]);
    });
});

describe('round trip', function () {
    it('maps the output of Serializer::toArray() back', function (object $object) {
        expect(Mapper::fromArray($object::class, Serializer::toArray($object)))->toEqual($object);
    })->with([
        'list<T>' => fn () => new EmptyListHolder([]),
        'array<T>' => fn () => new EmptyArrayHolder([]),
        'array<string, T>' => fn () => new EmptyStringMapHolder([]),
        'array<int, T>' => fn () => new EmptyIntMapHolder([]),
        'shape' => fn () => new EmptyOptionalShapeHolder([]),
        'class with no properties' => fn () => new EmptyNoPropertiesHolder(new EmptyNoProperties),
        'list of a union' => fn () => new EmptyUnionListHolder([]),
        'nested member' => fn () => new EmptyMemberParent(new EmptyMember([], new EmptyDefaults)),
    ]);

    it('maps a nested object with nothing written back', function () {
        $object = new EmptyMissingMember(new EmptyAllMissing(Missing::instance(), Missing::instance()));

        expect(Serializer::toArray($object))->toBe(['nested' => []])
            ->and(Mapper::fromArray(EmptyMissingMember::class, ['nested' => []])->nested->a)->toBeInstanceOf(Missing::class);
    });

    it('keeps rejecting a non-empty type that a constructed object violates', function () {
        expect(emptyErrors(NonEmptyListHolder::class, Serializer::toArray(new NonEmptyListHolder([]))))
            ->toBe(['data.v' => ['The data.v field is required.']]);
    });
});

it('accepts #[Rule] on an array alongside the new required rule', function () {
    expect(emptyErrors(EmptyRuleHolder::class, ['v' => []]))->toBe([])
        ->and(emptyErrors(EmptyRuleHolder::class, ['v' => ['a', 'b']]))->toBe(['data.v' => ['The data.v field must not have more than 1 items.']])
        ->and(emptyErrors(EmptyRuleHolder::class, []))->toBe(['data.v' => ['The data.v field is required.']]);
});

readonly class EmptyRuleHolder
{
    /** @param list<string> $v */
    public function __construct(
        #[Rule('max:1')]
        public array $v,
    ) {}
}
