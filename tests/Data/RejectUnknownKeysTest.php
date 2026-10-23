<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\IgnoreIfEmpty;
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\RejectUnknownKeys;
use BYanelli\Roma\Attributes\SnakeCaseKeys;
use BYanelli\Roma\Config\ConfigKey;
use BYanelli\Roma\Config\ConfigObject;
use BYanelli\Roma\Config\ConfigValidationException;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Missing;

#[RejectUnknownKeys]
class StrictOptions
{
    public string $color = 'gray';

    #[IgnoreIfEmpty]
    public string|Missing $label;
}

readonly class LooseSettings
{
    /**
     * @param  list<StrictOptions>  $items
     */
    public function __construct(
        public ?StrictOptions $options = null,
        public array $items = [],
    ) {}
}

#[RejectUnknownKeys]
class StrictBase
{
    public string $name = '';
}

class StrictChild extends StrictBase
{
    public int $size = 0;
}

#[RejectUnknownKeys]
#[SnakeCaseKeys]
class StrictKeys
{
    public string $firstName = '';

    #[Key('error.message')]
    public string $message = '';

    #[Key('id')]
    public int $identifier = 0;

    public string $fullName {
        get => $this->firstName;
    }
}

#[RejectUnknownKeys]
readonly class StrictCard
{
    public function __construct(public string $last4) {}
}

#[RejectUnknownKeys]
readonly class StrictVoucher
{
    public function __construct(public string $code) {}
}

readonly class StrictPayment
{
    public function __construct(
        #[Discriminated([StrictCard::class, StrictVoucher::class])]
        public StrictCard|StrictVoucher $payment,
    ) {}
}

#[RejectUnknownKeys(message: 'The :attribute key is not allowed.')]
readonly class StrictWithMessage
{
    public function __construct(public string $name = '') {}
}

readonly class UnknownKeysOnProperty
{
    public function __construct(
        #[RejectUnknownKeys]
        public ?StrictOptions $options = null,
    ) {}
}

#[ConfigObject('services.strict')]
#[RejectUnknownKeys]
final readonly class StrictConfig
{
    public function __construct(
        public string $apiKey = '',
        #[ConfigKey('app.name')]
        public string $appName = '',
    ) {}
}

/**
 * @return array<string, list<string>>
 */
function unknownKeyErrors(string $class, array $data): array
{
    try {
        Mapper::fromArray($class, $data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a MappingValidationException.');
}

it('rejects each key that no property maps from', function () {
    expect(unknownKeyErrors(StrictOptions::class, ['color' => 'red', 'size' => 2, 'k' => null, 'blank' => '']))->toBe([
        'data.size' => ['The data.size field is prohibited.'],
        'data.k' => ['The data.k field is prohibited.'],
        'data.blank' => ['The data.blank field is prohibited.'],
    ]);
});

it('accepts known keys, absent keys and an empty object', function () {
    expect(Mapper::fromArray(StrictOptions::class, ['color' => 'red'])->color)->toBe('red')
        ->and(Mapper::fromArray(StrictOptions::class, [])->color)->toBe('gray')
        ->and(Mapper::fromArray(LooseSettings::class, ['options' => []])->options?->color)->toBe('gray');
});

it('counts the key of an ignored value as known', function () {
    expect(Mapper::fromArray(StrictOptions::class, ['label' => ''])->label)->toBe(Missing::instance());
});

it('rejects unknown keys of a nested object and of the objects in a list', function () {
    expect(unknownKeyErrors(LooseSettings::class, [
        'options' => ['color' => 'red', 'k' => 'v'],
        'items' => [['color' => 'blue'], ['shade' => 'dark']],
        'extra' => true,
    ]))->toBe([
        'data.options.k' => ['The data.options.k field is prohibited.'],
        'data.items.1.shade' => ['The data.items.1.shade field is prohibited.'],
    ]);
});

it('reports only the object message for a list given for an object', function () {
    expect(unknownKeyErrors(LooseSettings::class, ['options' => ['x']]))
        ->toBe(['data.options' => ['The data.options field must be an object.']]);
});

it('rejects the elements of a list at the top level', function () {
    expect(unknownKeyErrors(StrictOptions::class, ['x', 'y']))->toBe([
        'data.0' => ['The data.0 field is prohibited.'],
        'data.1' => ['The data.1 field is prohibited.'],
    ]);
});

it('applies to subclasses', function () {
    expect(Mapper::fromArray(StrictChild::class, ['name' => 'a', 'size' => '2'])->size)->toBe(2)
        ->and(unknownKeyErrors(StrictChild::class, ['name' => 'a', 'color' => 'red']))
        ->toBe(['data.color' => ['The data.color field is prohibited.']]);
});

it('uses the keys properties map from, including dotted and virtual ones', function () {
    $keys = Mapper::fromArray(StrictKeys::class, ['first_name' => 'Ada', 'error' => ['message' => 'x'], 'id' => 3, 'full_name' => 'Ada']);

    expect($keys->identifier)->toBe(3)
        ->and(unknownKeyErrors(StrictKeys::class, ['firstName' => 'Ada', 'identifier' => 3, 'error' => ['message' => 'x', 'code' => 1]]))
        ->toBe([
            'data.firstName' => ['The data.firstName field is prohibited.'],
            'data.identifier' => ['The data.identifier field is prohibited.'],
            'data.error.code' => ['The data.error.code field is prohibited.'],
        ]);
});

it('counts the discriminator of a union member as known', function () {
    expect(Mapper::fromArray(StrictPayment::class, ['payment' => ['type' => 'StrictCard', 'last4' => '4242']])->payment)
        ->toEqual(new StrictCard('4242'))
        ->and(unknownKeyErrors(StrictPayment::class, ['payment' => ['type' => 'StrictVoucher', 'code' => 'X', 'last4' => '4242']]))
        ->toBe(['data.payment.last4' => ['The data.payment.last4 field is prohibited.']]);
});

it('uses the message of the attribute', function () {
    expect(unknownKeyErrors(StrictWithMessage::class, ['k' => 1]))
        ->toBe(['data.k' => ['The data.k key is not allowed.']]);
});

it('rejects the attribute on a property', function () {
    expect(fn () => Mapper::fromArray(UnknownKeysOnProperty::class, []))->toThrow(
        RuntimeException::class,
        'Roma property "UnknownKeysOnProperty::$options" has #[RejectUnknownKeys], which applies only to a class.',
    );
});

it('checks the keys under a config object\'s prefix, but not those beside a #[ConfigKey]', function () {
    config(['services.strict' => ['api_key' => 'k'], 'app.name' => 'Roma']);

    expect(Mapper::fromConfig(StrictConfig::class)->appName)->toBe('Roma');

    config(['services.strict' => ['api_key' => 'k', 'timeout' => 5]]);

    expect(fn () => Mapper::fromConfig(StrictConfig::class))->toThrow(
        fn (ConfigValidationException $e) => expect($e->errors())->toBe([
            'services.strict.timeout' => ['The services.strict.timeout field is prohibited.'],
        ]),
    );
});
