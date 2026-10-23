<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Delimited;
use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\Message;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Attributes\RulePerItem;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\JsonSchema\ClassSchema;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;

readonly class PerItemContact
{
    /**
     * @param  list<string>  $emails
     */
    public function __construct(
        #[RulePerItem('email')]
        public array $emails = [],
    ) {}
}

readonly class PerItemCard
{
    public function __construct(public string $last4) {}
}

readonly class PerItemVoucher
{
    public function __construct(public string $code) {}
}

readonly class PerItemSettings
{
    /**
     * @param  list<string>|null  $emails
     * @param  array<string, int>  $limits
     * @param  list<string>  $sizes
     * @param  list<string>  $urls
     * @param  list<string>  $codes
     * @param  list<string>  $tags
     * @param  list<list<int>>  $grid
     * @param  list<PerItemCard|PerItemVoucher>  $payments
     */
    public function __construct(
        #[RulePerItem('email')]
        public ?array $emails = null,
        #[RulePerItem('min:1', 'max:10')]
        public array $limits = [],
        #[RulePerItem(new In(['s', 'm', 'l']))]
        public array $sizes = [],
        #[RulePerItem(['url:http,https', 'max:20'])]
        public array $urls = [],
        #[RulePerItem('max:3', self::startsWithX(...))]
        public array $codes = [],
        #[Message(email: ':attribute is not an email address.', max: 'Send at most :max tags.')]
        #[Rule('max:2')]
        #[RulePerItem('email')]
        public array $tags = [],
        #[RulePerItem('array', 'max:2')]
        public array $grid = [],
        #[Discriminated([PerItemCard::class, PerItemVoucher::class])]
        #[RulePerItem('array')]
        public array $payments = [],
        public ?PerItemContact $contact = null,
    ) {}

    public static function startsWithX(): string
    {
        return 'starts_with:x';
    }
}

/**
 * @return array<string, list<string>>
 */
function perItemErrors(array $data): array
{
    try {
        Mapper::fromArray(PerItemSettings::class, $data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a MappingValidationException.');
}

it('validates each item with the rules, under the item\'s key', function () {
    expect(perItemErrors(['emails' => ['a@x.test', 'bad', 'c@x.test', 'worse']]))->toBe([
        'data.emails.1' => ['The data.emails.1 field must be a valid email address.'],
        'data.emails.3' => ['The data.emails.3 field must be a valid email address.'],
    ]);
});

it('maps items that pass the rules', function () {
    $settings = Mapper::fromArray(PerItemSettings::class, [
        'emails' => ['a@x.test'],
        'limits' => ['daily' => '5'],
        'sizes' => ['s', 'l'],
        'urls' => ['https://x.test'],
        'codes' => ['x1'],
        'grid' => [[1, 2]],
        'payments' => [['type' => 'PerItemCard', 'last4' => '4242']],
    ]);

    expect($settings->emails)->toBe(['a@x.test'])
        ->and($settings->limits)->toBe(['daily' => 5])
        ->and($settings->payments)->toEqual([new PerItemCard('4242')]);
});

it('allows a null or absent array', function () {
    expect(Mapper::fromArray(PerItemSettings::class, ['emails' => null])->emails)->toBeNull()
        ->and(Mapper::fromArray(PerItemSettings::class, [])->emails)->toBeNull();
});

it('accepts the rule forms #[Rule] does', function () {
    expect(perItemErrors([
        'limits' => ['daily' => 0],
        'sizes' => ['xl'],
        'urls' => ['ftp://x.test', 'https://example.test/a-long-path'],
        'codes' => ['y', 'x1234'],
    ]))->toBe([
        'data.limits.daily' => ['The data.limits.daily field must be at least 1.'],
        'data.sizes.0' => ['The selected data.sizes.0 is invalid.'],
        'data.urls.0' => ['The data.urls.0 field must be a valid URL.'],
        'data.urls.1' => ['The data.urls.1 field must not be greater than 20 characters.'],
        'data.codes.0' => ['The data.codes.0 field must start with one of the following: x.'],
        'data.codes.1' => ['The data.codes.1 field must not be greater than 3 characters.'],
    ]);
});

it('puts the rules before the item type\'s rule, as #[Rule] does', function () {
    expect(perItemErrors(['emails' => [['a@x.test']]]))->toBe([
        'data.emails.0' => [
            'The data.emails.0 field must be a valid email address.',
            'The data.emails.0 field must be a string.',
        ],
    ]);
});

it('applies only to the items, not to items of items', function () {
    expect(perItemErrors(['grid' => [[1, 2, 3], [1]]]))->toBe([
        'data.grid.0' => ['The data.grid.0 field must not have more than 2 items.'],
    ]);
});

it('applies to the items of an array of discriminated classes', function () {
    expect(perItemErrors(['payments' => ['x']]))->toBe([
        'data.payments.0' => ['The data.payments.0 field must be an array.'],
    ]);
});

it('applies to an array in a nested object', function () {
    expect(perItemErrors(['contact' => ['emails' => ['bad']]]))->toBe([
        'data.contact.emails.0' => ['The data.contact.emails.0 field must be a valid email address.'],
    ]);
});

it('uses the property\'s messages for its items', function () {
    expect(perItemErrors(['tags' => ['bad', 'a@x.test', 'worse']]))->toBe([
        'data.tags' => ['Send at most 2 tags.'],
        'data.tags.0' => ['data.tags.0 is not an email address.'],
        'data.tags.2' => ['data.tags.2 is not an email address.'],
    ]);
});

readonly class PerItemScalar
{
    public function __construct(
        #[RulePerItem('email')]
        public string $email,
    ) {}
}

readonly class PerItemShape
{
    /**
     * @param  array{email: string}  $contact
     */
    public function __construct(
        #[RulePerItem('email')]
        public array $contact,
    ) {}
}

it('throws on a property that is not an array', function (string $class) {
    Mapper::fromArray($class, []);
})->with([PerItemScalar::class, PerItemShape::class])
    ->throws(RuntimeException::class, 'has #[RulePerItem], which validates the items of an array, but its type is not an array.');

readonly class PerItemRequest
{
    /**
     * @param  list<string>  $emails
     */
    public function __construct(
        #[Delimited]
        #[RulePerItem('email')]
        public array $emails,
    ) {}
}

function perItemRequestErrors(TestCase $test, array $query): array
{
    $test->setRequest(query: $query);

    try {
        $test->mapRequest(PerItemRequest::class);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a ValidationException.');
}

it('validates the items of a request property, after #[Delimited] splits it', function () {
    /** @var TestCase $this */
    expect(perItemRequestErrors($this, ['emails' => 'a@x.test, bad']))->toBe([
        'input.emails.1' => ['The input.emails.1 field must be a valid email address.'],
    ]);

    config()->set('roma.source_prefixed_errors', false);

    expect(perItemRequestErrors($this, ['emails' => 'a@x.test, bad']))->toBe([
        'emails.1' => ['The emails.1 field must be a valid email address.'],
    ]);
});

it('adds the items\' rules to the JSON Schema of the items', function () {
    $properties = array_map(fn ($type) => $type->toArray(), ClassSchema::properties(PerItemContact::class));

    expect($properties['emails'])->toEqual(['type' => 'array', 'items' => ['type' => 'string', 'format' => 'email'], 'default' => []]);
});
