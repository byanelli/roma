<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\IgnoreIfEmpty;
use BYanelli\Roma\Attributes\Message;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Tests\Support\Discriminated\Checkout;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

readonly class ListForObjectOptions
{
    public function __construct(
        public string $color = 'gray',
        public int $size = 1,
    ) {}
}

readonly class ListForObjectRequest
{
    /**
     * @param  list<ListForObjectOptions>  $items
     */
    public function __construct(
        #[IgnoreIfEmpty]
        public ListForObjectOptions|Missing $config,
        public ?ListForObjectOptions $options = null,
        public array $items = [],
        #[Message(object: 'Send the labels as an object.')]
        public ?ListForObjectOptions $labels = null,
    ) {}
}

/**
 * @return array<string, list<string>>
 */
function listForObjectErrors(string $class, array $data): array
{
    try {
        Mapper::fromArray($class, $data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a MappingValidationException.');
}

it('rejects a list for an object', function () {
    expect(listForObjectErrors(ListForObjectRequest::class, ['options' => ['x']]))
        ->toBe(['data.options' => ['The data.options field must be an object.']])
        ->and(listForObjectErrors(ListForObjectRequest::class, ['options' => ['a', 'b']]))
        ->toBe(['data.options' => ['The data.options field must be an object.']]);
});

it('maps an empty array as an empty object', function () {
    expect(Mapper::fromArray(ListForObjectRequest::class, ['options' => []])->options)
        ->toEqual(new ListForObjectOptions);
});

it('keeps the array message for a value that is not an array', function () {
    expect(listForObjectErrors(ListForObjectRequest::class, ['options' => 'x']))
        ->toBe(['data.options' => ['The data.options field must be an array.']]);
});

it('rejects a list for an object in a list of objects', function () {
    expect(listForObjectErrors(ListForObjectRequest::class, ['items' => [['color' => 'red'], [1, 2]]]))
        ->toBe(['data.items.1' => ['The data.items.1 field must be an object.']]);
});

it('rejects a list for a discriminated union', function () {
    expect(listForObjectErrors(Checkout::class, [
        'payment' => ['Card'],
        'shipping' => ['kind' => 'Standard', 'days' => '3'],
        'refundTo' => ['type' => 'Voucher', 'code' => 'THANKS'],
    ]))
        ->toBe([
            'data.payment' => ['The data.payment field must be an object.'],
            'data.payment.type' => ['The data.payment.type field must be one of: Card, bank.'],
        ]);
});

it('uses the object message of #[Message]', function () {
    expect(listForObjectErrors(ListForObjectRequest::class, ['labels' => ['x']]))
        ->toBe(['data.labels' => ['Send the labels as an object.']]);
});

it('rejects a list after #[IgnoreIfEmpty], which ignores only an empty one', function () {
    expect(Mapper::fromArray(ListForObjectRequest::class, ['config' => []])->config)->toBe(Missing::instance())
        ->and(listForObjectErrors(ListForObjectRequest::class, ['config' => ['x']]))
        ->toBe(['data.config' => ['The data.config field must be an object.']]);
});

it('rejects a list for an object in a JSON request body', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['options' => ['x']]);

    expect(fn () => $this->mapRequest(ListForObjectRequest::class))->toThrow(
        fn (ValidationException $e) => expect($e->errors())->toBe(['input.options' => ['The input.options field must be an object.']]),
    );
});

it('reads a list at the top level by its keys', function () {
    expect(Mapper::fromArray(ListForObjectOptions::class, ['x']))->toEqual(new ListForObjectOptions);
});
