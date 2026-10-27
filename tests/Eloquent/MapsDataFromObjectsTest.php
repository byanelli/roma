<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\MergeOnUpdate;
use BYanelli\Roma\Attributes\OptionalOnUpdate;
use BYanelli\Roma\Attributes\RequiredOnCreate;
use BYanelli\Roma\Attributes\SnakeCaseKeys;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Eloquent\AsMappedObject;
use BYanelli\Roma\Eloquent\MapsDataFromObjects;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Missing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

#[SnakeCaseKeys]
readonly class MergeSettingsInput
{
    public function __construct(
        public string|Missing $theme,
        public int|Missing $pageSize,
        public string|Missing|null $apiUrl,
    ) {}
}

#[SnakeCaseKeys]
readonly class MergeSettings
{
    public function __construct(
        public string $theme = 'light',
        public int $pageSize = 20,
        public ?string $apiUrl = null,
    ) {}
}

#[SnakeCaseKeys]
readonly class MergeAccountData
{
    public function __construct(
        #[MergeOnUpdate]
        public MergeSettingsInput|Missing|null $userSettings,

        #[Key('prefs')]
        #[MergeOnUpdate]
        public MergeSettingsInput|Missing|null $preferences,

        public MergeSettingsInput|Missing|null $plainSettings,
    ) {}
}

readonly class MergeIntoStringData
{
    public function __construct(
        #[Key('name')]
        #[MergeOnUpdate]
        public MergeSettingsInput|Missing|null $settings,
    ) {}
}

readonly class MergeOnStringData
{
    public function __construct(
        #[MergeOnUpdate]
        public string|Missing|null $settings,
    ) {}
}

/**
 * @property ?array<string, mixed> $user_settings
 * @property ?MergeSettings $prefs
 * @property ?array<string, mixed> $plain_settings
 */
class MergeAccount extends Model
{
    /** @use MapsDataFromObjects<object, object> */
    use MapsDataFromObjects;

    public $timestamps = false;

    protected $fillable = ['name', 'user_settings', 'prefs', 'plain_settings'];

    protected function casts(): array
    {
        return [
            'user_settings' => 'array',
            'prefs' => AsMappedObject::of(MergeSettings::class, defaultWhenNull: true),
            'plain_settings' => 'array',
        ];
    }
}

beforeEach(function () {
    Schema::create('mapped_contacts', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email_address');
        $table->string('phone')->nullable()->default('none');
        $table->boolean('is_admin')->default(false);
    });

    Schema::create('merge_accounts', function (Blueprint $table) {
        $table->id();
        $table->string('name')->default('');
        $table->json('user_settings')->nullable();
        $table->json('prefs')->nullable();
        $table->json('plain_settings')->nullable();
    });
});

function mergeAccount(): MergeAccount
{
    return MergeAccount::query()->create([
        'name' => 'Ada',
        'user_settings' => ['theme' => 'dark', 'page_size' => 50, 'api_url' => 'https://a.test'],
        'prefs' => ['theme' => 'dark', 'page_size' => 50, 'api_url' => 'https://a.test'],
        'plain_settings' => ['theme' => 'dark', 'page_size' => 50],
    ])->refresh();
}

function mergeData(array $input): MergeAccountData
{
    return Mapper::fromArray(MergeAccountData::class, $input, Operation::Update);
}

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

it('merges a #[MergeOnUpdate] object over the stored value on update', function () {
    $account = mergeAccount();

    $account->updateFromObject(mergeData([
        'user_settings' => ['page_size' => 10, 'api_url' => null],
        'prefs' => ['theme' => 'light'],
    ]));

    expect(json_decode(DB::table('merge_accounts')->value('user_settings'), true))->toBe(['theme' => 'dark', 'page_size' => 10, 'api_url' => null])
        ->and($account->refresh()->prefs)->toEqual(new MergeSettings('light', 50, 'https://a.test'));
});

it('replaces a nested object without #[MergeOnUpdate]', function () {
    $account = mergeAccount();

    $account->updateFromObject(mergeData(['plain_settings' => ['page_size' => 10]]));

    expect($account->refresh()->plain_settings)->toBe(['page_size' => 10]);
});

it('clears a #[MergeOnUpdate] column on null and leaves it alone when Missing', function () {
    $account = mergeAccount();

    $account->updateFromObject(mergeData(['user_settings' => null]));

    expect($account->refresh()->user_settings)->toBeNull()
        ->and($account->prefs)->toEqual(new MergeSettings('dark', 50, 'https://a.test'));
});

it('sets a #[MergeOnUpdate] object as it is when the column is null', function () {
    $account = MergeAccount::query()->create(['name' => 'Ada'])->refresh();

    $account->updateFromObject(mergeData(['user_settings' => ['theme' => 'dark'], 'prefs' => ['theme' => 'dark']]));

    // A null prefs column reads as the default object, which is not merged.
    expect($account->refresh()->user_settings)->toBe(['theme' => 'dark'])
        ->and(json_decode(DB::table('merge_accounts')->value('prefs'), true))->toBe(['theme' => 'dark', 'page_size' => 20, 'api_url' => null]);
});

it('ignores #[MergeOnUpdate] on create', function () {
    $data = Mapper::fromArray(MergeAccountData::class, ['user_settings' => ['theme' => 'dark']], Operation::Create);

    expect(MergeAccount::createFromObject($data)->refresh()->user_settings)->toBe(['theme' => 'dark']);
});

it('throws when a #[MergeOnUpdate] column cannot be merged', function () {
    $account = mergeAccount();

    expect(fn () => $account->updateFromObject(new MergeIntoStringData(new MergeSettingsInput('dark', Missing::instance(), Missing::instance()))))->toThrow(
        InvalidArgumentException::class,
        "Roma cannot merge MergeIntoStringData's name into MergeAccount {$account->id}'s name, since the attribute has no array, JSON or class cast.",
    );
});

it('throws when #[MergeOnUpdate] is on a property that is not a nested object', function () {
    expect(fn () => Mapper::fromArray(MergeOnStringData::class, []))->toThrow(
        RuntimeException::class,
        'has #[MergeOnUpdate], which merges a nested object into a stored JSON object, but its type is not a nested object class.',
    );
});
