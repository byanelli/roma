<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Message;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

enum MessageSize: string
{
    case Small = 's';
    case Large = 'l';
}

readonly class MessageAddress
{
    public function __construct(
        #[Message(required: 'Send the city.')]
        public string $city,
    ) {}
}

readonly class MessageSettings
{
    /**
     * @param  list<MessageAddress>  $addresses
     */
    public function __construct(
        #[Message(required: 'Send a name.', max: ':attribute is too long.')]
        #[Rule('max:3')]
        public string $name,

        #[Message('Send a whole number.')]
        #[Rule('min:1')]
        public int $count,

        #[Message(boolean: 'Send yes or no.')]
        public ?bool $active = null,

        #[Message(enum: 'Pick a size.')]
        public ?MessageSize $size = null,

        public ?MessageAddress $address = null,

        public array $addresses = [],

        public ?int $limit = null,
    ) {}
}

function messageErrors(array $data): array
{
    try {
        Mapper::fromArray(MessageSettings::class, $data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a MappingValidationException.');
}

it('uses a message for the rule that failed', function () {
    expect(messageErrors(['count' => 1]))->toMatchArray(['data.name' => ['Send a name.']])
        ->and(messageErrors(['name' => 'Ada Lovelace', 'count' => 1]))->toMatchArray(['data.name' => ['data.name is too long.']]);
});

it('uses a message without a rule name for any of the property\'s rules', function () {
    expect(messageErrors(['name' => 'Ada', 'count' => 'many']))->toBe(['data.count' => ['Send a whole number.']])
        ->and(messageErrors(['name' => 'Ada', 'count' => 0]))->toBe(['data.count' => ['Send a whole number.']])
        ->and(messageErrors(['name' => 'Ada']))->toBe(['data.count' => ['Send a whole number.']]);
});

it('applies a Laravel rule name to the rule object Roma validates it with', function () {
    expect(messageErrors(['name' => 'Ada', 'count' => 1, 'active' => ['x']]))->toBe(['data.active' => ['Send yes or no.']])
        ->and(messageErrors(['name' => 'Ada', 'count' => 1, 'size' => 'm']))->toBe(['data.size' => ['Pick a size.']])
        ->and(messageErrors(['name' => 'Ada', 'count' => 1, 'address' => []]))->toBe(['data.address.city' => ['Send the city.']]);
});

it('applies a nested property\'s message to the elements of a list', function () {
    expect(messageErrors(['name' => 'Ada', 'count' => 1, 'addresses' => [['city' => 'Rome'], []]]))
        ->toBe(['data.addresses.1.city' => ['Send the city.']]);
});

it('leaves the messages of other properties alone', function () {
    expect(messageErrors(['name' => 'Ada', 'count' => 1, 'limit' => 'many']))
        ->toBe(['data.limit' => ['The data.limit field must be an integer.']]);
});

it('applies messages to a request', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['count' => 1]);

    try {
        $this->mapRequest(MessageSettings::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['input.name' => ['Send a name.']]);
    }
});

it('requires a message', function () {
    expect(fn () => new Message)->toThrow(InvalidArgumentException::class, '#[Message] needs at least one message');
});
