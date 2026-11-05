<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\Guard;
use BYanelli\Roma\Attributes\MapFromValue;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Tests\TestCase;

class NestedGuardLog
{
    /** @var list<string> */
    public static array $calls = [];
}

beforeEach(function () {
    NestedGuardLog::$calls = [];
});

readonly class GuardedLeaf
{
    public function __construct(public string $code = 'a') {}

    #[Guard]
    public function check(): void
    {
        NestedGuardLog::$calls[] = "leaf:{$this->code}";

        if ($this->code === 'bad') {
            throw new DomainException('bad leaf');
        }
    }
}

readonly class GuardedBranch
{
    public function __construct(public GuardedLeaf $leaf = new GuardedLeaf) {}

    #[Guard]
    public function check(): void
    {
        NestedGuardLog::$calls[] = 'branch';
    }
}

readonly class GuardedRoot
{
    public function __construct(public GuardedBranch $branch = new GuardedBranch) {}

    #[Guard]
    public function check(): void
    {
        NestedGuardLog::$calls[] = 'root';
    }
}

readonly class GuardedCollections
{
    /**
     * @param  list<GuardedLeaf>  $list
     * @param  array<string, GuardedLeaf>  $map
     * @param  array{item?: GuardedLeaf}  $shape
     */
    public function __construct(
        public array $list = [],
        public array $map = [],
        public array $shape = [],
        public ?GuardedLeaf $nullable = null,
    ) {}
}

#[Discriminated([GuardedCard::class])]
interface GuardedPayment {}

readonly class GuardedCard implements GuardedPayment
{
    public function __construct(public string $last4 = '1234') {}

    #[Guard]
    public function check(): void
    {
        NestedGuardLog::$calls[] = "card:{$this->last4}";
    }
}

readonly class GuardedWithUnion
{
    public function __construct(public GuardedPayment $payment) {}
}

readonly class GuardedSameLeafTwice
{
    public function __construct(public GuardedLeaf $first, public GuardedLeaf $second) {}
}

readonly class GuardedTransformed
{
    public function __construct(
        #[MapFromValue(static function (string $code): GuardedLeaf {
            return new GuardedLeaf('from-callable');
        })]
        public GuardedLeaf $leaf,
    ) {}
}

$map = fn (string $class, array $data) => Mapper::fromArray($class, $data);

describe('when mapping', function () use ($map) {
    it('runs nested guards before their parents', function () use ($map) {
        $map(GuardedRoot::class, ['branch' => ['leaf' => ['code' => 'x']]]);

        expect(NestedGuardLog::$calls)->toBe(['leaf:x', 'branch', 'root']);
    });

    it('runs the guards of objects in lists, maps and shapes', function () use ($map) {
        $map(GuardedCollections::class, [
            'list' => [['code' => 'l0'], ['code' => 'l1']],
            'map' => ['k' => ['code' => 'm']],
            'shape' => ['item' => ['code' => 's']],
            'nullable' => ['code' => 'n'],
        ]);

        expect(NestedGuardLog::$calls)->toBe(['leaf:l0', 'leaf:l1', 'leaf:m', 'leaf:s', 'leaf:n']);
    });

    it('runs the guards of a union member', function () use ($map) {
        $map(GuardedWithUnion::class, ['payment' => ['type' => 'GuardedCard', 'last4' => '9']]);

        expect(NestedGuardLog::$calls)->toBe(['card:9']);
    });

    it('stops at a guard that throws', function () use ($map) {
        expect(fn () => $map(GuardedRoot::class, ['branch' => ['leaf' => ['code' => 'bad']]]))
            ->toThrow(DomainException::class, 'bad leaf')
            ->and(NestedGuardLog::$calls)->toBe(['leaf:bad']);
    });

    it('runs no guards when validation fails', function () use ($map) {
        expect(fn () => $map(GuardedRoot::class, ['branch' => ['leaf' => ['code' => ['not a string']]]]))
            ->toThrow(MappingValidationException::class)
            ->and(NestedGuardLog::$calls)->toBe([]);
    });

    it('runs nested guards for a request', function () {
        /** @var TestCase $this */
        $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['branch' => ['leaf' => ['code' => 'x']]]);

        $this->mapRequest(GuardedRoot::class);

        expect(NestedGuardLog::$calls)->toBe(['leaf:x', 'branch', 'root']);
    });
});

describe('when validating a constructed object', function () {
    it('runs nested guards before their parents', function () {
        Mapper::validate(new GuardedRoot(new GuardedBranch(new GuardedLeaf('x'))));

        expect(NestedGuardLog::$calls)->toBe(['leaf:x', 'branch', 'root']);
    });

    it('runs the guards of objects in lists, maps and shapes', function () {
        Mapper::validate(new GuardedCollections(
            list: [new GuardedLeaf('l0'), new GuardedLeaf('l1')],
            map: ['k' => new GuardedLeaf('m')],
            shape: ['item' => new GuardedLeaf('s')],
            nullable: new GuardedLeaf('n'),
        ));

        expect(NestedGuardLog::$calls)->toBe(['leaf:l0', 'leaf:l1', 'leaf:m', 'leaf:s', 'leaf:n']);
    });

    it('runs the guards of a union member', function () {
        Mapper::validate(new GuardedWithUnion(new GuardedCard('9')));

        expect(NestedGuardLog::$calls)->toBe(['card:9']);
    });

    it('stops at a guard that throws', function () {
        expect(fn () => Mapper::validate(new GuardedRoot(new GuardedBranch(new GuardedLeaf('bad')))))
            ->toThrow(DomainException::class, 'bad leaf')
            ->and(NestedGuardLog::$calls)->toBe(['leaf:bad']);
    });

    it('runs no guards when validation fails', function () {
        expect(fn () => Mapper::validate(new GuardedCollections(list: ['not an object'])))
            ->toThrow(MappingValidationException::class)
            ->and(NestedGuardLog::$calls)->toBe([]);
    });

    it('runs the guards of an object used twice once', function () {
        $leaf = new GuardedLeaf('x');

        Mapper::validate(new GuardedSameLeafTwice($leaf, $leaf));

        expect(NestedGuardLog::$calls)->toBe(['leaf:x']);
    });

    // A #[MapFromValue] callable built this object, and the property isn't validated.
    it('skips an object in a property with #[MapFromValue]', function () {
        Mapper::validate(new GuardedTransformed(new GuardedLeaf('x')));

        expect(NestedGuardLog::$calls)->toBe([]);
    });
});
