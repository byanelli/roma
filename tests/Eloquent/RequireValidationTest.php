<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\OptionalOnUpdate;
use BYanelli\Roma\Attributes\RequiredOnCreate;
use BYanelli\Roma\Config\ConfigObject;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Data\ValidatedObjects;
use BYanelli\Roma\Eloquent\MapsDataFromObjects;
use BYanelli\Roma\Eloquent\UnvalidatedObjectException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Http\Request\Injection\Request;
use BYanelli\Roma\Http\Request\Injection\RequestBody;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

readonly class CheckedContactData
{
    public function __construct(
        #[OptionalOnUpdate]
        public string|Missing $name,

        #[OptionalOnUpdate]
        public string|Missing $email,
    ) {}
}

readonly class CheckedSignupData
{
    public function __construct(
        public string $name,

        #[RequiredOnCreate]
        public string|Missing $email,
    ) {}
}

readonly class CheckedMemberData
{
    public function __construct(
        #[RequiredOnCreate]
        public string|Missing $email,
    ) {}
}

readonly class CheckedTeamData
{
    public function __construct(
        public string $name,
        public ?CheckedMemberData $owner = null,
    ) {}
}

readonly class CheckedMemberListData
{
    /**
     * @param  list<CheckedMemberData>  $members
     */
    public function __construct(public array $members) {}
}

readonly class CheckedShapeData
{
    /**
     * @param  array{owner: CheckedMemberData}  $meta
     */
    public function __construct(public array $meta) {}
}

#[Discriminated([CheckedCompanyParty::class, CheckedPersonParty::class])]
interface CheckedParty {}

readonly class CheckedCompanyParty implements CheckedParty
{
    public function __construct(public string $name) {}
}

readonly class CheckedPersonParty implements CheckedParty
{
    public function __construct(
        #[RequiredOnCreate]
        public string|Missing $email,
    ) {}
}

readonly class CheckedUnionData
{
    public function __construct(public CheckedParty $party) {}
}

readonly class CheckedNodeData
{
    /**
     * @param  list<CheckedNodeData>  $children
     */
    public function __construct(
        public string $name,
        public array $children = [],
    ) {}
}

readonly class CheckedRecursiveMemberData
{
    public function __construct(
        public ?CheckedRecursiveMemberData $parent = null,
        public ?CheckedMemberData $member = null,
    ) {}
}

#[Request]
readonly class CheckedContactRequest
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}

#[ConfigObject('services.checked_contact')]
readonly class CheckedContactConfig
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}

/**
 * @property int $id
 * @property string $name
 * @property string $email
 */
class CheckedContact extends Model
{
    /** @use MapsDataFromObjects<object, object, object> */
    use MapsDataFromObjects;

    public $timestamps = false;

    protected $fillable = ['name', 'email'];
}

beforeEach(function () {
    Schema::create('checked_contacts', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
    });

    Route::post('/checked/body', fn (
        #[RequestBody]
        CheckedContactData $data,
    ) => CheckedContact::createFromObject($data));

    Route::post('/checked/request', fn (CheckedContactRequest $request) => CheckedContact::createFromObject($request));
});

function uncheckedContact(): CheckedContactData
{
    return new CheckedContactData('Ada', 'ada@example.com');
}

function storedContact(): CheckedContact
{
    return CheckedContact::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
}

it('stores an object Roma did not validate by default', function () {
    expect(CheckedContact::createFromObject(uncheckedContact())->exists)->toBeTrue();
});

it('stores objects from fromArray() and validate()', function () {
    Mapper::requireValidation();

    CheckedContact::createFromObject(Mapper::fromArray(CheckedContactData::class, ['name' => 'Ada', 'email' => 'ada@example.com']));
    CheckedContact::upsertFromObject(Mapper::validate(new CheckedContactData('Bob', 'bob@example.com'), Operation::Create), ['email']);

    expect(CheckedContact::query()->count())->toBe(2);
});

it('stores objects mapped from a #[RequestBody] parameter and a #[Request] class', function () {
    /** @var TestCase $this */
    Mapper::requireValidation();

    $this->postJson('/checked/body', ['name' => 'Ada', 'email' => 'ada@example.com'])->assertCreated();
    $this->postJson('/checked/request', ['name' => 'Bob', 'email' => 'bob@example.com'])->assertCreated();

    expect(CheckedContact::query()->pluck('name')->all())->toBe(['Ada', 'Bob']);
});

it('stores a config object, injected or from Mapper::fromConfig()', function () {
    Mapper::requireValidation();
    config(['services.checked_contact' => ['name' => 'Ada', 'email' => 'ada@example.com']]);

    CheckedContact::createFromObject(app(CheckedContactConfig::class));
    CheckedContact::upsertFromObject(Mapper::fromConfig(CheckedContactConfig::class), ['email']);

    expect(CheckedContact::query()->count())->toBe(1);
});

it('throws for an object Roma did not validate', function (Closure $store, string $method) {
    Mapper::requireValidation();
    $contact = storedContact();

    expect(fn () => $store($contact, uncheckedContact()))->toThrow(
        UnvalidatedObjectException::class,
        "Roma cannot store CheckedContactData with CheckedContact::{$method}(), since it was not validated. "
        .'Map it with Roma, or pass it to Mapper::validate() first.',
    );
})->with([
    'create' => [fn (CheckedContact $contact, object $data) => CheckedContact::createFromObject($data), 'createFromObject'],
    'update' => [fn (CheckedContact $contact, object $data) => $contact->updateFromObject($data), 'updateFromObject'],
    'upsert' => [fn (CheckedContact $contact, object $data) => CheckedContact::upsertFromObject($data, ['email']), 'upsertFromObject'],
    'upsert many' => [fn (CheckedContact $contact, object $data) => CheckedContact::upsertManyFromObjects([$data], ['email']), 'upsertManyFromObjects'],
]);

it('throws for one unvalidated object among validated ones in upsertManyFromObjects()', function () {
    Mapper::requireValidation();

    expect(fn () => CheckedContact::upsertManyFromObjects([
        Mapper::fromArray(CheckedContactData::class, ['name' => 'Ada', 'email' => 'ada@example.com']),
        new CheckedContactData('Bob', 'bob@example.com'),
    ], ['email']))->toThrow(UnvalidatedObjectException::class, 'CheckedContact::upsertManyFromObjects()');

    expect(CheckedContact::query()->count())->toBe(0);
});

it('stores an object validated for an Update only with updateFromObject()', function () {
    Mapper::requireValidation();
    $data = Mapper::fromArray(CheckedContactData::class, ['name' => 'Grace'], Operation::Update);

    expect(storedContact()->updateFromObject($data))->toBeTrue()
        ->and(fn () => CheckedContact::createFromObject($data))->toThrow(
            UnvalidatedObjectException::class,
            'Roma cannot store CheckedContactData with CheckedContact::createFromObject(), since it was validated for an Update, '
            .'which lets required properties be Missing. Map it with Operation::Create, or pass it to Mapper::validate() with Operation::Create.',
        )
        ->and(fn () => CheckedContact::upsertFromObject($data, ['email']))->toThrow(UnvalidatedObjectException::class, 'CheckedContact::upsertFromObject()')
        ->and(fn () => CheckedContact::upsertManyFromObjects([$data], ['email']))->toThrow(UnvalidatedObjectException::class, 'CheckedContact::upsertManyFromObjects()');
});

it('stores an object validated for a Create with updateFromObject()', function () {
    Mapper::requireValidation();

    expect(storedContact()->updateFromObject(Mapper::validate(new CheckedContactData('Grace', 'grace@example.com'), Operation::Create)))->toBeTrue();
});

it('stores an object passed to markValidated()', function () {
    Mapper::requireValidation();

    expect(CheckedContact::createFromObject(Mapper::markValidated(uncheckedContact()))->exists)->toBeTrue()
        ->and(fn () => CheckedContact::createFromObject(Mapper::markValidated(uncheckedContact(), Operation::Update)))
        ->toThrow(UnvalidatedObjectException::class, 'since it was validated for an Update');
});

it('does not treat a clone of a validated object as validated', function () {
    Mapper::requireValidation();
    $data = Mapper::validate(uncheckedContact());

    expect(fn () => CheckedContact::createFromObject(clone $data))->toThrow(UnvalidatedObjectException::class, 'since it was not validated');
});

it('can be turned off again', function () {
    Mapper::requireValidation();
    Mapper::requireValidation(false);

    expect(CheckedContact::createFromObject(uncheckedContact())->exists)->toBeTrue();
});

it('is off in a new application, so it does not carry over between tests', function () {
    /** @var TestCase $this */
    Mapper::requireValidation();

    $this->refreshApplication();

    expect(app(ValidatedObjects::class)->isRequired())->toBeFalse();
});

it('removes an object from the map once it is garbage collected', function () {
    $validatedObjects = app(ValidatedObjects::class);
    $count = $validatedObjects->count();

    $data = Mapper::validate(uncheckedContact());

    expect($validatedObjects->count())->toBe($count + 1);

    unset($data);
    gc_collect_cycles();

    expect($validatedObjects->count())->toBe($count);
});

it('stores an object validated with Operation::None when its class has no #[RequiredOnCreate] property', function () {
    Mapper::requireValidation();

    CheckedContact::createFromObject(Mapper::validate(uncheckedContact()));
    CheckedContact::upsertFromObject(Mapper::validate(new CheckedContactData('Bob', 'bob@example.com')), ['email']);

    expect(CheckedContact::query()->count())->toBe(2);
});

it('stores an object validated with Operation::None only with updateFromObject() when its class has #[RequiredOnCreate] properties', function (Closure $data) {
    Mapper::requireValidation();
    $data = $data();

    expect(storedContact()->updateFromObject($data))->toBeTrue()
        ->and(fn () => CheckedContact::createFromObject($data))->toThrow(
            UnvalidatedObjectException::class,
            sprintf(
                'Roma cannot store %1$s with CheckedContact::createFromObject(), since it was validated with Operation::None, and %1$s or a '
                .'class nested in it has #[RequiredOnCreate] properties, which only a Create requires. Validate it with Operation::Create, '
                .'as in Mapper::validate($object, Operation::Create) or Mapper::fromArray(%1$s::class, $data, Operation::Create).',
                $data::class,
            ),
        )
        ->and(fn () => CheckedContact::upsertFromObject($data, ['email']))->toThrow(UnvalidatedObjectException::class, 'CheckedContact::upsertFromObject(), since it was validated with Operation::None')
        ->and(fn () => CheckedContact::upsertManyFromObjects([$data], ['email']))->toThrow(UnvalidatedObjectException::class, 'CheckedContact::upsertManyFromObjects(), since it was validated with Operation::None');
})->with([
    // Validation with Operation::Create rejects both: the empty string as required, and the Missing member.
    'top-level property' => [fn () => Mapper::validate(new CheckedSignupData('Grace', ''))],
    'nested property' => [fn () => Mapper::validate(new CheckedTeamData('Grace', new CheckedMemberData(Missing::instance())))],
]);

it('stores an object with #[RequiredOnCreate] properties validated with Operation::Create', function () {
    Mapper::requireValidation();

    CheckedContact::createFromObject(Mapper::fromArray(CheckedSignupData::class, ['name' => 'Ada', 'email' => 'ada@example.com'], Operation::Create));
    CheckedContact::upsertFromObject(Mapper::validate(new CheckedSignupData('Bob', 'bob@example.com'), Operation::Create), ['email']);

    expect(CheckedContact::query()->count())->toBe(2);
});

it('finds #[RequiredOnCreate] properties in nested classes', function (string $class, bool $expected) {
    expect(app(ValidatedObjects::class)->hasRequiredOnCreate($class))->toBe($expected);
})->with([
    'property' => [CheckedSignupData::class, true],
    'nested object' => [CheckedTeamData::class, true],
    'list of objects' => [CheckedMemberListData::class, true],
    'shape item' => [CheckedShapeData::class, true],
    'union member' => [CheckedUnionData::class, true],
    'after a recursive property' => [CheckedRecursiveMemberData::class, true],
    'recursive class without one' => [CheckedNodeData::class, false],
    'class without one' => [CheckedContactData::class, false],
]);
