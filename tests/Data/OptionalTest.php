<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Attributes\Present;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Tests\Support\Discriminated\Card;
use BYanelli\Roma\Tests\Support\Discriminated\PaymentMethod;
use BYanelli\Roma\Tests\Support\Discriminated\Voucher;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

class TestOptionalProfile
{
    #[Optional]
    public string $website;

    #[Optional]
    public ?string $company;

    #[Optional]
    public string $locale = 'en';

    public function __construct(
        public string $name,
        #[Optional] public ?string $nickname,
        #[Optional] public string|Missing $bio,
        #[Optional] public int|Missing $age,
        #[Optional] public Card|Missing $card,
        #[Optional] public Card|Missing|null $backupCard,
        #[Optional, Discriminated([Card::class, Voucher::class])] public Card|Voucher|Missing $refund,
        #[Optional] public PaymentMethod|Missing $payment,
        #[Optional] public string $title = 'none',
    ) {}
}

function mapOptional(array $data): TestOptionalProfile
{
    return Mapper::fromArray(TestOptionalProfile::class, $data);
}

/**
 * @return array<string, list<string>>
 */
function optionalErrors(array $data): array
{
    try {
        mapOptional($data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a MappingValidationException');
}

it('maps absent #[Optional] properties to their default, Missing or null, or leaves them unset', function () {
    $mapped = mapOptional(['name' => 'Bill']);

    expect($mapped->nickname)->toBeNull()
        ->and($mapped->bio)->toBe(Missing::instance())
        ->and($mapped->age)->toBe(Missing::instance())
        ->and($mapped->card)->toBe(Missing::instance())
        ->and($mapped->backupCard)->toBe(Missing::instance())
        ->and($mapped->refund)->toBe(Missing::instance())
        ->and($mapped->payment)->toBe(Missing::instance())
        ->and($mapped->title)->toBe('none')
        ->and(new ReflectionProperty($mapped, 'website')->isInitialized($mapped))->toBeFalse()
        ->and(new ReflectionProperty($mapped, 'company')->isInitialized($mapped))->toBeFalse()
        ->and($mapped->locale)->toBe('en');
});

it('maps present #[Optional] properties as their type without Missing', function () {
    $mapped = mapOptional([
        'name' => 'Bill',
        'website' => 'https://example.test',
        'company' => null,
        'locale' => 'fr',
        'bio' => 'Hi',
        'age' => '40',
        'card' => ['last4' => '4242'],
        'backupCard' => null,
        'refund' => ['type' => 'Voucher', 'code' => 'THANKS'],
        'payment' => ['type' => 'Card', 'last4' => '1111'],
    ]);

    expect($mapped->website)->toBe('https://example.test')
        ->and($mapped->company)->toBeNull()
        ->and($mapped->locale)->toBe('fr')
        ->and($mapped->bio)->toBe('Hi')
        ->and($mapped->age)->toBe(40)
        ->and($mapped->card)->toEqual(new Card('4242'))
        ->and($mapped->backupCard)->toBeNull()
        ->and($mapped->refund)->toEqual(new Voucher('THANKS'))
        ->and($mapped->payment)->toEqual(new Card('1111'));
});

it('validates a present #[Optional] value against its type', function () {
    expect(optionalErrors(['name' => 'Bill', 'bio' => null, 'age' => 'old', 'card' => ['saved' => true], 'website' => true]))->toEqual([
        'data.website' => ['The data.website field must be a string.'],
        'data.bio' => ['The data.bio field must be a string.'],
        'data.age' => ['The data.age field must be an integer.'],
        'data.card.last4' => ['The data.card.last4 field is required.'],
    ]);
});

class TestOptionalWithoutAbsentValue
{
    public function __construct(#[Optional] public string $bio) {}
}

it('rejects an #[Optional] parameter with no value for when it is absent', function () {
    expect(fn () => Mapper::fromArray(TestOptionalWithoutAbsentValue::class, []))
        ->toThrow(RuntimeException::class, 'Roma parameter "TestOptionalWithoutAbsentValue::$bio" is #[Optional] but has no value for when it is absent. Make it nullable (?string), add Missing to its type (string|Missing), or give it a default.');
});

it('serializes a mapped object back to the same data', function () {
    $data = [
        'name' => 'Bill',
        'nickname' => null,
        'title' => 'none',
        'locale' => 'en',
    ];

    expect(Serializer::toArray(mapOptional($data)))->toEqual($data);

    $data = [
        'name' => 'Bill',
        'nickname' => 'B',
        'bio' => 'Hi',
        'age' => 40,
        'card' => ['last4' => '4242', 'saved' => false],
        'backupCard' => null,
        'refund' => ['type' => 'Voucher', 'code' => 'THANKS'],
        'payment' => ['type' => 'Card', 'last4' => '1111', 'saved' => true],
        'title' => 'Dr',
        'website' => 'https://example.test',
        'company' => 'Acme',
        'locale' => 'fr',
    ];

    expect(Serializer::toArray(mapOptional($data)))->toEqual($data);
});

it('omits a property whose value is Missing', function () {
    $object = new class(Missing::instance())
    {
        public function __construct(public string|Missing $bio, public string $name = 'Bill') {}
    };

    expect(Serializer::toArray($object))->toBe(['name' => 'Bill']);
});

it('emits an #[Optional] Missing-typed property as an optional TypeScript field', function () {
    $ts = new TypeScriptGenerator([TestOptionalProfile::class], [TestOptionalProfile::class])->generate();

    expect($ts)->not->toContain('Missing')
        ->toContain('  bio?: string;')
        ->toContain('  age?: number;')
        ->toContain('  card?: Card;')
        ->toContain('  backupCard?: Card | null;')
        ->toContain('  website?: string;')
        ->toContain('  name: string;');
});

class TestMissingTyped
{
    public int|Missing $age;

    public function __construct(
        public string $name,
        public string|Missing $bio,
        public Card|Missing|null $card,
        #[Optional] public string|Missing $title,
    ) {}
}

class TestMissingTypedWithPresent
{
    public function __construct(#[Present] public string|Missing|null $bio) {}
}

it('treats a type with Missing as optional without #[Optional]', function () {
    $mapped = Mapper::fromArray(TestMissingTyped::class, ['name' => 'Bill']);

    expect($mapped->bio)->toBe(Missing::instance())
        ->and($mapped->card)->toBe(Missing::instance())
        ->and($mapped->title)->toBe(Missing::instance())
        ->and($mapped->age)->toBe(Missing::instance())
        ->and(Serializer::toArray($mapped))->toBe(['name' => 'Bill']);
});

it('validates a present value of a type with Missing', function () {
    $map = fn () => Mapper::fromArray(TestMissingTyped::class, ['name' => 'Bill', 'bio' => true, 'age' => 'old']);

    expect($map)->toThrow(fn (MappingValidationException $e) => expect($e->errors())->toEqual([
        'data.bio' => ['The data.bio field must be a string.'],
        'data.age' => ['The data.age field must be an integer.'],
    ]));
});

it('rejects #[Present] on a type with Missing', function () {
    expect(fn () => Mapper::fromArray(TestMissingTypedWithPresent::class, []))
        ->toThrow(RuntimeException::class, 'is typed with Missing, which allows the key to be absent, and has #[Present]');
});

it('omits an unset property typed with Missing when serializing', function () {
    $object = new class
    {
        public string|Missing $bio;

        public string $name = 'Bill';
    };

    expect(Serializer::toArray($object))->toBe(['name' => 'Bill']);
});

it('emits a type with Missing as an optional TypeScript field', function (array $requests, array $responses) {
    $ts = new TypeScriptGenerator($requests, $responses)->generate();

    expect($ts)->toContain("export interface TestMissingTypedBody {\n  name: string;\n  bio?: string;\n  card?: Card | null;\n  title?: string;\n  age?: number;\n}");
})->with([
    'request' => [[TestMissingTyped::class], []],
    'response' => [[], [TestMissingTyped::class]],
]);

class TestOnlyMissing
{
    public function __construct(public Missing $bio) {}
}

class TestNullableMissing
{
    public function __construct(public ?Missing $bio) {}
}

class TestMissingOrNull
{
    public function __construct(public ?Missing $bio) {}
}

it('rejects a type with nothing but Missing', function (string $class) {
    $message = "Roma property \"{$class}::\$bio\" has no type besides Missing. Add the type of its value, e.g. string|Missing.";

    expect(fn () => Mapper::fromArray($class, []))
        ->toThrow(RuntimeException::class, $message)
        ->and(fn () => Serializer::toArray(new $class(Missing::instance())))
        ->toThrow(RuntimeException::class, $message);
})->with([TestOnlyMissing::class, TestNullableMissing::class, TestMissingOrNull::class]);
