<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\MapFromValue;
use BYanelli\Roma\Attributes\OptionalOnUpdate;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Eloquent\Mapped;
use BYanelli\Roma\Eloquent\StoredFromObjects;
use BYanelli\Roma\Eloquent\StringBacked;
use BYanelli\Roma\Eloquent\UnvalidatedObjectException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Missing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

readonly class CheckedCastConfig
{
    public function __construct(
        #[Rule('max:3')]
        public string $foo = 'bar',
    ) {}
}

readonly class CheckedCastPartial
{
    public function __construct(
        #[OptionalOnUpdate]
        public string|Missing $foo,
    ) {}
}

readonly class CheckedCastInput
{
    public function __construct(
        public string $name,
        public CheckedCastConfig $config,
    ) {}
}

readonly class CheckedCastItems
{
    /**
     * @param  list<CheckedCastConfig>  $items
     */
    public function __construct(public array $items) {}
}

readonly class CheckedCastTransformed
{
    public function __construct(
        #[MapFromValue(static function (string $value): CheckedCastConfig {
            return new CheckedCastConfig($value);
        })]
        public CheckedCastConfig $config,
    ) {}
}

final readonly class CheckedCastCode implements StringBacked
{
    public function __construct(public string $code) {}

    public static function parse(string $value): ?static
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->code;
    }
}

/**
 * @property ?CheckedCastConfig $config
 * @property ?CheckedCastPartial $partial
 * @property ?CheckedCastCode $code
 */
class CheckedCastRecord extends Model
{
    /** @use StoredFromObjects<CheckedCastInput, CheckedCastInput> */
    use StoredFromObjects;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'config' => Mapped::to(CheckedCastConfig::class),
            'partial' => Mapped::to(CheckedCastPartial::class),
            'code' => Mapped::to(CheckedCastCode::class),
        ];
    }
}

beforeEach(function () {
    Schema::create('checked_cast_records', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->json('config')->nullable();
        $table->json('partial')->nullable();
        $table->string('code')->nullable();
    });
});

describe('with the check off', function () {
    it('stores an object built with new', function () {
        $record = new CheckedCastRecord;
        $record->config = new CheckedCastConfig('toolong');

        expect($record->getAttributes()['config'])->toBe('{"foo":"toolong"}');
    });
});

describe('with the check on', function () {
    beforeEach(function () {
        Mapper::preventStoringUnvalidatedObjects();
    });

    it('rejects an object built with new, and stores nothing', function () {
        $record = new CheckedCastRecord;

        expect(function () use ($record) {
            $record->config = new CheckedCastConfig;
        })->toThrow(UnvalidatedObjectException::class, 'Roma cannot store CheckedCastConfig with CheckedCastRecord::$config, since it was not validated.')
            ->and($record->getAttributes())->not->toHaveKey('config');
    });

    it('rejects an object built with new in create()', function () {
        CheckedCastRecord::query()->create(['config' => new CheckedCastConfig]);
    })->throws(UnvalidatedObjectException::class);

    it('rejects a clone', function () {
        $validated = Mapper::validate(new CheckedCastConfig);
        $record = new CheckedCastRecord;

        $record->config = clone $validated;
    })->throws(UnvalidatedObjectException::class);

    it('accepts an object from Mapper::validate()', function () {
        $record = new CheckedCastRecord;
        $record->config = Mapper::validate(new CheckedCastConfig);

        expect($record->config->foo)->toBe('bar');
    });

    it('accepts an object from Mapper::fromArray()', function () {
        $record = new CheckedCastRecord;
        $record->config = Mapper::fromArray(CheckedCastConfig::class, ['foo' => 'baz']);

        expect($record->config->foo)->toBe('baz');
    });

    it('accepts an object from Mapper::markValidated()', function () {
        $record = new CheckedCastRecord;
        $record->config = Mapper::markValidated(new CheckedCastConfig);

        expect($record->config->foo)->toBe('bar');
    });

    it('accepts an object nested in a mapped object', function () {
        $input = Mapper::fromArray(CheckedCastInput::class, ['name' => 'Bill', 'config' => ['foo' => 'baz']]);
        $record = new CheckedCastRecord;
        $record->config = $input->config;

        expect($record->config->foo)->toBe('baz');
    });

    it('accepts an object nested in a validated object', function () {
        $input = Mapper::validate(new CheckedCastInput('Bill', new CheckedCastConfig));
        $record = new CheckedCastRecord;
        $record->config = $input->config;

        expect($record->config->foo)->toBe('bar');
    });

    it('accepts an item of a mapped list', function () {
        $input = Mapper::fromArray(CheckedCastItems::class, ['items' => [['foo' => 'baz']]]);
        $record = new CheckedCastRecord;
        $record->config = $input->items[0];

        expect($record->config->foo)->toBe('baz');
    });

    // Its callable built the value, and validation skips the property.
    it('rejects the object in a #[MapFromValue] property', function () {
        $input = Mapper::validate(new CheckedCastTransformed(new CheckedCastConfig));
        $record = new CheckedCastRecord;

        $record->config = $input->config;
    })->throws(UnvalidatedObjectException::class);

    it('accepts an attribute read back from the cast', function () {
        $record = CheckedCastRecord::query()->create(['config' => ['foo' => 'baz']]);
        $record->config = $record->config;

        expect($record->config->foo)->toBe('baz');
    });

    it('accepts an object validated under Create or None', function (Operation $operation) {
        $record = new CheckedCastRecord;
        $record->partial = Mapper::validate(new CheckedCastPartial('bar'), $operation);

        expect($record->partial->foo)->toBe('bar');
    })->with([Operation::Create, Operation::None]);

    // An Update lets required properties be Missing, so the stored JSON might not read back.
    it('rejects an object validated under Update', function () {
        $record = new CheckedCastRecord;

        $record->partial = Mapper::validate(new CheckedCastPartial(Missing::instance()), Operation::Update);
    })->throws(UnvalidatedObjectException::class, 'since it was validated for an Update');

    it('rejects an object nested in one validated under Update', function () {
        $input = Mapper::fromArray(CheckedCastInput::class, ['name' => 'Bill', 'config' => []], Operation::Update);
        $record = new CheckedCastRecord;

        $record->config = $input->config;
    })->throws(UnvalidatedObjectException::class, 'since it was validated for an Update');

    it('still maps an array, and rejects an invalid one', function () {
        $record = new CheckedCastRecord;
        $record->config = ['foo' => 'baz'];

        expect($record->config->foo)->toBe('baz')
            ->and(function () use ($record) {
                $record->config = ['foo' => 'toolong'];
            })->toThrow(MappingValidationException::class);
    });

    it('accepts null', function () {
        $record = new CheckedCastRecord;
        $record->config = null;

        expect($record->config)->toBeNull();
    });

    // parse() and toString() define a StringBacked value, and Roma doesn't map it.
    it('accepts a StringBacked object built with new', function () {
        $record = new CheckedCastRecord;
        $record->code = new CheckedCastCode('abc');

        expect($record->code->code)->toBe('abc');
    });

    // The object is serialized to an array, which the cast maps.
    it('stores a validated object with an unrecorded nested object through createFromObject()', function () {
        $record = CheckedCastRecord::createFromObject(new CheckedCastInput(
            name: 'Bill',
            config: new CheckedCastConfig(foo: 'bar'),
        ) |> Mapper::validate(...));

        expect($record->fresh()->config->foo)->toBe('bar');
    });

    it('turns off again', function () {
        Mapper::preventStoringUnvalidatedObjects(false);

        $record = new CheckedCastRecord;
        $record->config = new CheckedCastConfig;

        expect($record->config->foo)->toBe('bar');
    });
});
