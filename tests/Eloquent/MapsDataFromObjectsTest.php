<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\OptionalOnUpdate;
use BYanelli\Roma\Attributes\RequiredOnCreate;
use BYanelli\Roma\Attributes\SnakeCaseKeys;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Eloquent\MapsDataFromObjects;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Missing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

#[SnakeCaseKeys]
readonly class MappedContactData
{
    public function __construct(
        #[OptionalOnUpdate]
        public string|Missing $name,

        #[RequiredOnCreate]
        public string|Missing $emailAddress,

        public string|Missing|null $phone,

        public bool $isAdmin = false,
    ) {}
}

/**
 * @property int $id
 * @property string $name
 * @property string $email_address
 * @property ?string $phone
 * @property bool $is_admin
 */
class MappedContact extends Model
{
    /** @use MapsDataFromObjects<MappedContactData, MappedContactData> */
    use MapsDataFromObjects;

    public $timestamps = false;

    protected $fillable = ['name', 'email_address', 'phone'];
}

beforeEach(function () {
    Schema::create('mapped_contacts', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email_address');
        $table->string('phone')->nullable()->default('none');
        $table->boolean('is_admin')->default(false);
    });
});

it('creates a model from an object', function () {
    $data = Mapper::fromArray(MappedContactData::class, ['name' => 'Ada', 'email_address' => 'ada@example.com', 'phone' => null], Operation::Create);

    $contact = MappedContact::createFromObject($data)->refresh();

    expect($contact->exists)->toBeTrue()
        ->and($contact->only('name', 'email_address', 'phone'))->toBe(['name' => 'Ada', 'email_address' => 'ada@example.com', 'phone' => null]);
});

it('leaves optional Missing properties to their database defaults on create', function () {
    $data = Mapper::fromArray(MappedContactData::class, ['name' => 'Ada', 'email_address' => 'ada@example.com'], Operation::Create);

    expect(MappedContact::createFromObject($data)->refresh()->phone)->toBe('none');
});

it('throws on create when a property required in a Create is Missing', function () {
    // Built directly, since mapping with Operation::Create would reject the input.
    expect(fn () => MappedContact::createFromObject(new MappedContactData('Ada', Missing::instance(), '555')))->toThrow(
        InvalidArgumentException::class,
        'Roma cannot serialize MappedContactData for a Create, since $emailAddress is Missing.',
    );

    expect(fn () => MappedContact::createFromObject(Mapper::fromArray(MappedContactData::class, [], Operation::Update)))->toThrow(
        InvalidArgumentException::class,
        'since $name, $emailAddress are Missing.',
    );

    expect(MappedContact::query()->count())->toBe(0);
});

it('updates only the provided properties', function () {
    $contact = MappedContact::createFromObject(new MappedContactData('Ada', 'ada@example.com', '555'));

    $data = Mapper::fromArray(MappedContactData::class, ['phone' => '777'], Operation::Update);

    expect($contact->updateFromObject($data))->toBeTrue()
        ->and($contact->refresh()->only('name', 'email_address', 'phone'))->toBe(['name' => 'Ada', 'email_address' => 'ada@example.com', 'phone' => '777']);
});

it('respects the model\'s $fillable', function () {
    $contact = MappedContact::createFromObject(new MappedContactData('Ada', 'ada@example.com', null, isAdmin: true));

    expect($contact->refresh()->is_admin)->toBeFalsy();

    $contact->updateFromObject(new MappedContactData(Missing::instance(), Missing::instance(), Missing::instance(), isAdmin: true));

    expect($contact->refresh()->is_admin)->toBeFalsy();
});
