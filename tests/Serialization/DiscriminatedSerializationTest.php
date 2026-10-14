<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Facades\Roma;
use BYanelli\Roma\Response\Response;
use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\Tests\Support\Discriminated\BankAccount;
use BYanelli\Roma\Tests\Support\Discriminated\Card;
use BYanelli\Roma\Tests\Support\Discriminated\Checkout;
use BYanelli\Roma\Tests\Support\Discriminated\Express;
use BYanelli\Roma\Tests\Support\Discriminated\PaymentMethod;
use BYanelli\Roma\Tests\Support\Discriminated\Refunds;
use BYanelli\Roma\Tests\Support\Discriminated\Standard;
use BYanelli\Roma\Tests\Support\Discriminated\Voucher;
use BYanelli\Roma\Tests\Support\Discriminated\Wallet;

function fullCheckout(): Checkout
{
    return new Checkout(
        payment: new BankAccount('DE89370400440532013000'),
        shipping: new Express('DHL', surcharge: new Card('9999', saved: true)),
        refundTo: new Voucher('THANKS'),
        fallbacks: [new Card('0005'), new BankAccount('GB33BUKB20201555555555')],
        saved: ['work' => new Card('1111')],
        backup: null,
        wallet: new Wallet(new Card('2222'), ['primary' => new BankAccount('NL91ABNA0417164300'), 'label' => 'main']),
    );
}

it('writes the discriminator first for values serialized through a discriminated property', function () {
    expect(Roma::toArray(fullCheckout(), Profile::storage()))->toBe([
        'payment' => ['type' => 'bank', 'iban' => 'DE89370400440532013000'],
        'shipping' => ['kind' => 'Express', 'carrier' => 'DHL', 'surcharge' => ['type' => 'Card', 'last4' => '9999', 'saved' => true]],
        'refundTo' => ['type' => 'Voucher', 'code' => 'THANKS'],
        'fallbacks' => [
            ['type' => 'Card', 'last4' => '0005', 'saved' => false],
            ['type' => 'bank', 'iban' => 'GB33BUKB20201555555555'],
        ],
        'saved' => ['work' => ['type' => 'Card', 'last4' => '1111', 'saved' => false]],
        'backup' => null,
        'wallet' => [
            'default' => ['type' => 'Card', 'last4' => '2222', 'saved' => false],
            'slots' => ['primary' => ['type' => 'bank', 'iban' => 'NL91ABNA0417164300'], 'label' => 'main'],
        ],
    ]);
});

it('writes the discriminator for elements of a PHPDoc union', function () {
    expect(Roma::toArray(new Refunds([new Voucher('A')])))->toBe(['refunds' => [['type' => 'Voucher', 'code' => 'A']]]);
});

class DirectCardResponse extends Response
{
    public function __construct(public Card $card) {}
}

it('omits the discriminator when the member is serialized directly or through its own class', function () {
    expect(Roma::toArray(new Card('4242')))->toBe(['last4' => '4242', 'saved' => false])
        ->and(Roma::toArray(new DirectCardResponse(new Card('4242'))))->toBe(['card' => ['last4' => '4242', 'saved' => false]]);
});

it('maps the storage output back to an equal object', function () {
    $checkout = fullCheckout();

    $stored = json_decode(json_encode(Roma::toArray($checkout, Profile::storage())), true);

    $mapped = app(ObjectMapper::class)->map(Checkout::class, new ArrayPayload($stored), new ArrayPolicy);

    expect($mapped)->toEqual($checkout);
});

it('maps the storage output of a minimal object back to an equal object', function () {
    $checkout = new Checkout(new Card('4242'), new Standard(3), new Card('1111'));

    $mapped = app(ObjectMapper::class)->map(Checkout::class, new ArrayPayload(Roma::toArray($checkout, Profile::storage())), new ArrayPolicy);

    expect($mapped)->toEqual($checkout);
});

readonly class UnlistedMethod implements PaymentMethod
{
    public function __construct(public string $token) {}
}

class UnlistedPaymentResponse extends Response
{
    public function __construct(public PaymentMethod $payment) {}
}

it('refuses to serialize a value that is not a member of the union', function () {
    expect(fn () => Roma::toArray(new UnlistedPaymentResponse(new UnlistedMethod('tok'))))->toThrow(
        RuntimeException::class,
        'Cannot serialize UnlistedMethod through UnlistedPaymentResponse::$payment: it is not a member of the discriminated union.',
    );
});

interface LooseContract {}

readonly class LooseImplementation implements LooseContract
{
    public function __construct(public int $id) {}
}

class LooseResponse extends Response
{
    /**
     * @param  array<string, int|string>  $tags
     */
    public function __construct(
        public LooseContract $item,
        public array $tags = [],
        public array $untyped = [],
    ) {}
}

it('serializes an undiscriminated interface and undocumented arrays as before', function () {
    expect(Roma::toArray(new LooseResponse(new LooseImplementation(1), ['a' => 1], [new LooseImplementation(2)])))->toBe([
        'item' => ['id' => 1],
        'tags' => ['a' => 1],
        'untyped' => [['id' => 2]],
    ]);
});
