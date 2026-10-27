<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\SnakeCaseKeys;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Eloquent\HasDefault;
use BYanelli\Roma\Eloquent\InvalidAttributeValue;
use BYanelli\Roma\Eloquent\Mapped;
use BYanelli\Roma\Eloquent\MappedObjectCast;
use BYanelli\Roma\Eloquent\StringBacked;
use BYanelli\Roma\Eloquent\StringBackedCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[SnakeCaseKeys]
readonly class CastSettings
{
    public function __construct(
        public string $theme = 'light',
        public int $pageSize = 20,
        public ?string $apiUrl = null,
    ) {}
}

readonly class CastCredentials
{
    public function __construct(
        public string $user,
        #[SensitiveParameter]
        public string $token,
    ) {}
}

final readonly class CastPostcode implements StringBacked
{
    public function __construct(public string $digits) {}

    public static function parse(string $value): ?static
    {
        return preg_match('/^\s*(\d{5})\s*$/', $value, $matches) === 1 ? new self($matches[1]) : null;
    }

    public function toString(): string
    {
        return $this->digits;
    }
}

final readonly class CastRegion implements HasDefault, StringBacked
{
    public function __construct(public string $code) {}

    public static function parse(string $value): ?static
    {
        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? new self($value) : null;
    }

    public static function default(): static
    {
        return new self('ZZ');
    }

    public function toString(): string
    {
        return $this->code;
    }
}

/**
 * @property ?CastSettings $settings
 * @property CastSettings $defaulted
 * @property ?CastSettings $lenient
 * @property ?CastPostcode $postcode
 * @property ?CastPostcode $lenient_postcode
 * @property ?CastCredentials $credentials
 * @property CastRegion $region
 * @property ?CastRegion $lenient_region
 */
class CastRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings' => Mapped::to(CastSettings::class),
            'defaulted' => Mapped::to(CastSettings::class, defaultWhenNull: true),
            'lenient' => Mapped::to(CastSettings::class, nullWhenInvalid: true),
            'postcode' => Mapped::class.':'.CastPostcode::class,
            'lenient_postcode' => Mapped::to(CastPostcode::class, nullWhenInvalid: true),
            'credentials' => Mapped::to(CastCredentials::class),
            'region' => Mapped::to(CastRegion::class, defaultWhenNull: true),
            'lenient_region' => Mapped::to(CastRegion::class, defaultWhenNull: true, nullWhenInvalid: true),
        ];
    }
}

beforeEach(function () {
    Schema::create('cast_records', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->json('settings')->nullable();
        $table->json('defaulted')->nullable();
        $table->json('lenient')->nullable();
        $table->string('postcode')->nullable();
        $table->string('lenient_postcode')->nullable();
        $table->json('credentials')->nullable();
        $table->string('region')->nullable();
        $table->string('lenient_region')->nullable();
    });
});

function storedCastRecord(array $columns): CastRecord
{
    $id = DB::table('cast_records')->insertGetId($columns);

    return CastRecord::findOrFail($id);
}

function storedColumn(CastRecord $record, string $column): mixed
{
    return DB::table('cast_records')->where('id', $record->id)->value($column);
}

describe('a JSON object column', function () {
    it('reads as the mapped class', function () {
        $record = storedCastRecord(['settings' => '{"theme": "dark", "page_size": "50", "unused": 1}']);

        expect($record->settings)->toEqual(new CastSettings('dark', 50));
    });

    it('reads null as null, or with DEFAULT_WHEN_NULL as the defaults without storing them', function () {
        $record = storedCastRecord([]);

        expect($record->settings)->toBeNull()
            ->and($record->defaulted)->toEqual(new CastSettings);

        $record->update(['name' => 'changed']);

        expect(storedColumn($record, 'defaulted'))->toBeNull();
    });

    it('stores an object with the storage profile', function () {
        $record = CastRecord::create(['settings' => new CastSettings(pageSize: 5)]);

        expect(json_decode(storedColumn($record, 'settings'), true))
            ->toBe(['theme' => 'light', 'page_size' => 5, 'api_url' => null]);
    });

    it('maps and validates an array before storing it', function () {
        $record = CastRecord::create(['settings' => ['page_size' => '5']]);

        expect(json_decode(storedColumn($record, 'settings'), true))
            ->toBe(['theme' => 'light', 'page_size' => 5, 'api_url' => null])
            ->and(fn () => CastRecord::create(['settings' => ['page_size' => 'many']]))
            ->toThrow(MappingValidationException::class);
    });

    it('stores null as null', function () {
        $record = CastRecord::create(['settings' => null, 'defaulted' => null, 'postcode' => null]);

        expect(storedColumn($record, 'settings'))->toBeNull()
            ->and(storedColumn($record, 'defaulted'))->toBeNull()
            ->and(storedColumn($record, 'postcode'))->toBeNull();
    });

    it('rejects a value of another type', function () {
        CastRecord::make(['settings' => 'dark']);
    })->throws(InvalidArgumentException::class, "An unsaved CastRecord's settings takes a CastSettings, an array or null, not string.");

    it('leaves the stored JSON alone when the model is saved after a read', function () {
        $record = storedCastRecord(['settings' => '{"theme": "dark", "unused": 1}']);

        expect($record->settings->theme)->toBe('dark');

        $record->update(['name' => 'changed']);

        expect(storedColumn($record, 'settings'))->toBe('{"theme": "dark", "unused": 1}');
    });

    it('serializes as the storage array', function () {
        $record = storedCastRecord(['settings' => '{"theme": "dark", "unused": 1}']);

        expect($record->toArray())->toMatchArray([
            'settings' => ['theme' => 'dark', 'page_size' => 20, 'api_url' => null],
            'defaulted' => ['theme' => 'light', 'page_size' => 20, 'api_url' => null],
            'lenient' => null,
        ]);
    });

    it('stores #[\SensitiveParameter] properties and leaves them out of the model\'s array form', function () {
        $record = CastRecord::create(['credentials' => new CastCredentials('ox', 'secret')]);

        expect(json_decode(storedColumn($record, 'credentials'), true))->toBe(['user' => 'ox', 'token' => 'secret'])
            ->and($record->fresh()?->credentials)->toEqual(new CastCredentials('ox', 'secret'))
            ->and($record->toArray()['credentials'])->toBe(['user' => 'ox']);
    });

    it('throws with the model and attribute when the JSON is not an object', function (string $json) {
        $record = storedCastRecord(['settings' => $json]);

        expect(fn () => $record->settings)->toThrow(
            InvalidAttributeValue::class,
            "CastRecord {$record->id} has an invalid settings: it is not a JSON object",
        );
    })->with(['{not json', '"dark"', '42']);

    it('throws when the driver returns a value other than a string', function () {
        (new MappedObjectCast(CastSettings::class))->get(new CastRecord, 'settings', 42, []);
    })->throws(InvalidAttributeValue::class, 'An unsaved CastRecord has an invalid settings: it is not a JSON object');

    it('throws with the mapping failure when a value is invalid', function () {
        $record = storedCastRecord(['settings' => '{"page_size": "many"}']);

        try {
            $record->settings;
            $this->fail('Expected an InvalidAttributeValue.');
        } catch (InvalidAttributeValue $e) {
            expect($e->getMessage())->toStartWith("CastRecord {$record->id} has an invalid settings: Roma could not map")
                ->and($e->getPrevious())->toBeInstanceOf(MappingValidationException::class)
                ->and($e->modelClass)->toBe(CastRecord::class)
                ->and($e->modelKey)->toBe($record->id)
                ->and($e->attribute)->toBe('settings');
        }
    });

    it('reads an invalid value as null with NULL_WHEN_INVALID', function (string $json) {
        expect(storedCastRecord(['lenient' => $json])->lenient)->toBeNull();
    })->with(['{not json', '{"page_size": "many"}']);
});

describe('a StringBacked column', function () {
    it('reads as the parsed class, and null as null', function () {
        expect(storedCastRecord(['postcode' => ' 02139'])->postcode)->toEqual(new CastPostcode('02139'))
            ->and(storedCastRecord([])->postcode)->toBeNull();
    });

    it('throws with the model and attribute when the string does not parse', function () {
        $record = storedCastRecord(['postcode' => 'nope']);

        expect(fn () => $record->postcode)->toThrow(
            InvalidAttributeValue::class,
            "CastRecord {$record->id} has an invalid postcode: it is not a valid CastPostcode",
        );
    });

    it('reads a string that does not parse as null with NULL_WHEN_INVALID', function () {
        expect(storedCastRecord(['lenient_postcode' => 'nope'])->lenient_postcode)->toBeNull();
    });

    it('stores an object as its string and a string as given', function () {
        $record = CastRecord::create(['postcode' => CastPostcode::parse(' 02139 '), 'lenient_postcode' => ' 02139']);

        expect(storedColumn($record, 'postcode'))->toBe('02139')
            ->and(storedColumn($record, 'lenient_postcode'))->toBe(' 02139');
    });

    it('rejects a string that does not parse, unless NULL_WHEN_INVALID is set', function () {
        $record = CastRecord::create(['lenient_postcode' => 'nope']);

        expect(storedColumn($record, 'lenient_postcode'))->toBe('nope')
            ->and(fn () => $record->update(['postcode' => 'nope']))
            ->toThrow(InvalidArgumentException::class, "CastRecord {$record->id}'s postcode takes a valid CastPostcode, and the string isn't one.");
    });

    it('rejects a value of another type', function () {
        CastRecord::make(['postcode' => 2139]);
    })->throws(InvalidArgumentException::class, "An unsaved CastRecord's postcode takes a CastPostcode, a string or null, not int.");

    it('leaves the stored string alone when the model is saved after a read', function () {
        $record = storedCastRecord(['postcode' => ' 02139']);

        expect($record->postcode)->not->toBeNull();

        $record->update(['name' => 'changed']);

        expect(storedColumn($record, 'postcode'))->toBe(' 02139');
    });

    it('serializes as the stored string', function () {
        $record = storedCastRecord(['postcode' => ' 02139', 'lenient_postcode' => 'nope']);

        expect($record->toArray())->toMatchArray(['postcode' => ' 02139', 'lenient_postcode' => null]);
    });
});

describe('a StringBacked column with DEFAULT_WHEN_NULL', function () {
    it('reads null as default() without storing it', function () {
        $record = storedCastRecord([]);

        expect($record->region)->toEqual(new CastRegion('ZZ'))
            ->and($record->lenient_region)->toEqual(new CastRegion('ZZ'))
            ->and($record->isDirty())->toBeFalse();

        $record->update(['name' => 'changed']);

        expect(storedColumn($record, 'region'))->toBeNull();
    });

    it('stores null as null and the default object as its string', function () {
        $record = CastRecord::create(['region' => null, 'lenient_region' => CastRegion::default()]);

        expect(storedColumn($record, 'region'))->toBeNull()
            ->and(storedColumn($record, 'lenient_region'))->toBe('ZZ');
    });

    it('reads a string that does not parse as null with NULL_WHEN_INVALID, and throws without it', function () {
        $record = storedCastRecord(['region' => 'nope', 'lenient_region' => 'nope']);

        expect($record->lenient_region)->toBeNull()
            ->and(fn () => $record->region)->toThrow(
                InvalidAttributeValue::class,
                "CastRecord {$record->id} has an invalid region: it is not a valid CastRegion",
            );
    });

    // A JSON column serializes its default object, and a string column serializes the stored string.
    it('serializes a null column as null', function () {
        $record = storedCastRecord(['lenient_region' => 'US']);

        expect($record->toArray())->toMatchArray(['region' => null, 'lenient_region' => 'US']);
    });
});

describe('the cast arguments', function () {
    it('chooses the cast by the class', function () {
        expect(Mapped::castUsing([CastSettings::class]))->toBeInstanceOf(MappedObjectCast::class)
            ->and(Mapped::castUsing([CastPostcode::class]))->toBeInstanceOf(StringBackedCast::class)
            ->and(Mapped::castUsing([CastRegion::class, Mapped::DEFAULT_WHEN_NULL]))->toBeInstanceOf(StringBackedCast::class);
    });

    it('lists the options after the class', function () {
        expect(Mapped::to(CastSettings::class))->toBe(Mapped::class.':'.CastSettings::class)
            ->and(Mapped::to(CastSettings::class, defaultWhenNull: true, nullWhenInvalid: true))
            ->toBe(Mapped::class.':'.CastSettings::class.',default-when-null,null-when-invalid');
    });

    it('throws for a missing class, an unknown option, or DEFAULT_WHEN_NULL on a StringBacked class without HasDefault', function (array $arguments, string $message) {
        expect(fn () => Mapped::castUsing($arguments))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'no class' => [[], 'Roma Mapped needs a class, as in Mapped::to(Settings::class).'],
        'not a class' => [['NoSuchClass'], 'Roma Mapped needs a class'],
        'unknown option' => [[CastSettings::class, 'lenient'], 'Roma Mapped has no option lenient.'],
        'default for a string' => [
            [CastPostcode::class, Mapped::DEFAULT_WHEN_NULL],
            "Roma Mapped's default-when-null option needs StringBacked class CastPostcode to implement BYanelli\\Roma\\Eloquent\\HasDefault.",
        ],
    ]);
});
