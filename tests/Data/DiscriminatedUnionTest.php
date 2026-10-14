<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\DiscriminatorValue;
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Tests\Support\Discriminated\BankAccount;
use BYanelli\Roma\Tests\Support\Discriminated\Card;
use BYanelli\Roma\Tests\Support\Discriminated\Checkout;
use BYanelli\Roma\Tests\Support\Discriminated\Express;
use BYanelli\Roma\Tests\Support\Discriminated\Other\Card as OtherCard;
use BYanelli\Roma\Tests\Support\Discriminated\PaymentMethod;
use BYanelli\Roma\Tests\Support\Discriminated\Refunds;
use BYanelli\Roma\Tests\Support\Discriminated\Standard;
use BYanelli\Roma\Tests\Support\Discriminated\Voucher;
use BYanelli\Roma\Tests\Support\Discriminated\Wallet;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

function mapDiscriminated(string $class, array $data): mixed
{
    return app(ObjectMapper::class)->map($class, new ArrayPayload($data), new ArrayPolicy);
}

function checkoutData(array $overrides = []): array
{
    return array_replace([
        'payment' => ['type' => 'Card', 'last4' => '4242'],
        'shipping' => ['kind' => 'Standard', 'days' => '3'],
        'refundTo' => ['type' => 'Voucher', 'code' => 'THANKS'],
    ], $overrides);
}

/**
 * @return array<string, list<string>>
 */
function discriminatedErrors(Closure $map): array
{
    try {
        $map();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a ValidationException');
}

it('maps a JSON body into the member the discriminator selects', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: checkoutData([
        'payment' => ['type' => 'bank', 'iban' => 'DE89370400440532013000'],
        'shipping' => ['kind' => 'Express', 'carrier' => 'UPS'],
    ]));

    $checkout = $this->mapRequest(Checkout::class);

    expect($checkout->payment)->toEqual(new BankAccount('DE89370400440532013000'))
        ->and($checkout->shipping)->toEqual(new Express('UPS'))
        ->and($checkout->refundTo)->toEqual(new Voucher('THANKS'));
});

it('maps a plain array, coercing the member fields', function () {
    $checkout = mapDiscriminated(Checkout::class, checkoutData([
        'payment' => ['type' => 'Card', 'last4' => '4242', 'saved' => 'yes'],
        'refundTo' => ['type' => 'Card', 'last4' => '1111'],
    ]));

    expect($checkout->payment)->toEqual(new Card('4242', saved: true))
        ->and($checkout->shipping)->toEqual(new Standard(3))
        ->and($checkout->refundTo)->toEqual(new Card('1111'));
});

it('maps lists and maps whose elements are different members', function () {
    $checkout = mapDiscriminated(Checkout::class, checkoutData([
        'fallbacks' => [
            ['type' => 'Card', 'last4' => '0005'],
            ['type' => 'bank', 'iban' => 'GB33BUKB20201555555555'],
        ],
        'saved' => [
            'work' => ['type' => 'bank', 'iban' => 'FR1420041010050500013M02606'],
        ],
    ]));

    expect($checkout->fallbacks)->toEqual([new Card('0005'), new BankAccount('GB33BUKB20201555555555')])
        ->and($checkout->saved)->toEqual(['work' => new BankAccount('FR1420041010050500013M02606')]);
});

it('maps a union nested in a member, an object and a shape', function () {
    $checkout = mapDiscriminated(Checkout::class, checkoutData([
        'shipping' => ['kind' => 'Express', 'carrier' => 'DHL', 'surcharge' => ['type' => 'Card', 'last4' => '9999']],
        'wallet' => [
            'default' => ['type' => 'bank', 'iban' => 'NL91ABNA0417164300'],
            'slots' => ['primary' => ['type' => 'Card', 'last4' => '1234']],
        ],
    ]));

    expect($checkout->shipping)->toEqual(new Express('DHL', new Card('9999')))
        ->and($checkout->wallet)->toEqual(new Wallet(new BankAccount('NL91ABNA0417164300'), ['primary' => new Card('1234')]));
});

it('reports an unknown discriminator value at the key, listing the allowed values', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: checkoutData(['payment' => ['type' => 'Cash']]));

    expect(discriminatedErrors(fn () => $this->mapRequest(Checkout::class)))->toBe([
        'input.payment.type' => ['The input.payment.type field must be one of: Card, bank.'],
    ]);
});

it('reports a missing discriminator under the data prefix', function () {
    $errors = discriminatedErrors(fn () => mapDiscriminated(Checkout::class, checkoutData([
        'shipping' => ['days' => 3],
    ])));

    expect($errors)->toBe([
        'data.shipping.kind' => ['The data.shipping.kind field must be one of: Standard, Express.'],
    ]);
});

it('reports errors per element of a list of members', function () {
    $errors = discriminatedErrors(fn () => mapDiscriminated(Checkout::class, checkoutData([
        'fallbacks' => [
            ['type' => 'Card', 'saved' => 'maybe'],
            ['type' => 'bank'],
            ['type' => 'Paypal'],
            // Coerced even though other elements are invalid.
            ['type' => 'Card', 'last4' => '0005', 'saved' => 'yes'],
        ],
        'saved' => ['home' => ['type' => 'Card']],
    ])));

    expect($errors)->toBe([
        'data.fallbacks.0.last4' => ['The data.fallbacks.0.last4 field is required.'],
        'data.fallbacks.0.saved' => ['The data.fallbacks.0.saved field must be true or false.'],
        'data.fallbacks.1.iban' => ['The data.fallbacks.1.iban field is required.'],
        'data.fallbacks.2.type' => ['The data.fallbacks.2.type field must be one of: Card, bank.'],
        'data.saved.home.last4' => ['The data.saved.home.last4 field is required.'],
    ]);
});

it('reports errors in a nested union', function () {
    $errors = discriminatedErrors(fn () => mapDiscriminated(Checkout::class, checkoutData([
        'shipping' => ['kind' => 'Express', 'carrier' => 'DHL', 'surcharge' => ['type' => 'Card']],
        'wallet' => ['default' => ['type' => 'bank'], 'slots' => ['primary' => ['last4' => '1234']]],
    ])));

    expect(array_keys($errors))->toBe([
        'data.shipping.surcharge.last4',
        'data.wallet.default.iban',
        'data.wallet.slots.primary.type',
    ]);
});

it('requires a member\'s fields only when the union value is present', function () {
    $checkout = mapDiscriminated(Checkout::class, checkoutData(['backup' => null]));

    expect($checkout->backup)->toBeNull();
});

it('reports a value that is not an object', function () {
    $errors = discriminatedErrors(fn () => mapDiscriminated(Checkout::class, checkoutData(['payment' => 'Card'])));

    expect($errors)->toBe(['data.payment' => ['The data.payment field must be an array.']]);
});

// --- Definition errors ---

readonly class UndiscriminatedRequest
{
    public function __construct(public Card|Voucher $refundTo) {}
}

it('requires #[Discriminated] on a native union of classes', function () {
    expect(fn () => mapDiscriminated(UndiscriminatedRequest::class, []))->toThrow(
        RuntimeException::class,
        'Roma property "UndiscriminatedRequest::$refundTo" is typed as the union '.Card::class.'|'.Voucher::class.'. '
        .'Add #[Discriminated([Card::class, Voucher::class])] to the property.',
    );
});

readonly class ScalarUnionRequest
{
    public function __construct(public int|string $id) {}
}

it('maps a native union of scalars as mixed', function () {
    expect(mapDiscriminated(ScalarUnionRequest::class, ['id' => 'abc'])->id)->toBe('abc');
});

readonly class MismatchedUnionRequest
{
    public function __construct(
        #[Discriminated([Card::class, BankAccount::class])]
        public Card|Voucher $refundTo,
    ) {}
}

it('requires a native union\'s attribute to list exactly its classes', function () {
    expect(fn () => mapDiscriminated(MismatchedUnionRequest::class, []))
        ->toThrow(RuntimeException::class, 'must list exactly the classes of the union type');
});

readonly class NonMemberRequest
{
    public function __construct(
        #[Discriminated([Card::class, Voucher::class])]
        public PaymentMethod $payment,
    ) {}
}

it('rejects a listed class that does not implement the interface', function () {
    expect(fn () => mapDiscriminated(NonMemberRequest::class, []))->toThrow(
        RuntimeException::class,
        'Roma #[Discriminated] on NonMemberRequest::$payment lists '.Voucher::class.', which does not extend or implement '.PaymentMethod::class.'.',
    );
});

readonly class AbstractMemberRequest
{
    public function __construct(
        #[Discriminated([Card::class, PaymentMethod::class])]
        public PaymentMethod $payment,
    ) {}
}

it('rejects a listed class that is not concrete', function () {
    expect(fn () => mapDiscriminated(AbstractMemberRequest::class, []))
        ->toThrow(RuntimeException::class, 'lists '.PaymentMethod::class.', which is not a concrete class.');
});

readonly class DuplicateNameRequest
{
    public function __construct(
        #[Discriminated([Card::class, OtherCard::class])]
        public PaymentMethod $payment,
    ) {}
}

it('rejects two members with the same short name', function () {
    expect(fn () => mapDiscriminated(DuplicateNameRequest::class, []))->toThrow(
        RuntimeException::class,
        Card::class.' and '.OtherCard::class.' both have the discriminator value "Card". '
        .'Give one of them a different value with #[DiscriminatorValue].',
    );
});

#[DiscriminatorValue('gift')]
readonly class GiftCard implements PaymentMethod
{
    public function __construct(public string $code) {}
}

readonly class OverrideRequest
{
    public function __construct(
        #[Discriminated([Card::class, GiftCard::class], key: 'method')]
        public PaymentMethod $payment,
    ) {}
}

it('uses a property-level attribute and key in place of the interface\'s', function () {
    $request = mapDiscriminated(OverrideRequest::class, ['payment' => ['method' => 'gift', 'code' => 'XMAS']]);

    expect($request->payment)->toEqual(new GiftCard('XMAS'))
        ->and(discriminatedErrors(fn () => mapDiscriminated(OverrideRequest::class, ['payment' => ['type' => 'Card']])))
        ->toBe(['data.payment.method' => ['The data.payment.method field must be one of: Card, gift.']]);
});

readonly class TypedMember implements PaymentMethod
{
    public function __construct(public string $type) {}
}

readonly class MemberWithKeyRequest
{
    public function __construct(
        #[Discriminated([Card::class, TypedMember::class])]
        public PaymentMethod $payment,
    ) {}
}

it('rejects a member with a property under the discriminator key', function () {
    expect(fn () => mapDiscriminated(MemberWithKeyRequest::class, []))->toThrow(
        RuntimeException::class,
        'Roma property "TypedMember::$type" uses the key "type", which is the discriminator key of a union the class belongs to.',
    );
});

readonly class RenamedTypeMember implements PaymentMethod
{
    public function __construct(#[Key('type')] public string $kind) {}
}

readonly class RenamedMemberRequest
{
    public function __construct(
        #[Discriminated([Card::class, RenamedTypeMember::class])]
        public PaymentMethod $payment,
    ) {}
}

it('rejects a member whose property is renamed to the discriminator key', function () {
    expect(fn () => mapDiscriminated(RenamedMemberRequest::class, []))
        ->toThrow(RuntimeException::class, '"RenamedTypeMember::$kind" uses the key "type"');
});

readonly class MisplacedAttributeRequest
{
    public function __construct(
        #[Discriminated([Card::class])]
        public Card $card,
    ) {}
}

it('rejects #[Discriminated] on a property typed as a concrete class', function () {
    expect(fn () => mapDiscriminated(MisplacedAttributeRequest::class, []))
        ->toThrow(RuntimeException::class, 'applies only to a property typed as an interface, an abstract class or a union of classes');
});

it('applies a property-level attribute to a PHPDoc union of array elements', function () {
    $request = mapDiscriminated(Refunds::class, ['refunds' => [
        ['type' => 'Voucher', 'code' => 'A'],
        ['type' => 'Card', 'last4' => '4242'],
    ]]);

    expect($request->refunds)->toEqual([new Voucher('A'), new Card('4242')]);
});

it('reports per-element errors under bare keys in a precognitive response', function () {
    /** @var TestCase $this */
    Route::post('/checkout', fn (Checkout $checkout) => 'ok')
        ->middleware(HandlePrecognitiveRequests::class);
    $this->app->bind(Checkout::class, fn () => $this->getRequestMapper()->mapRequest(Checkout::class));

    $this->withPrecognition()
        ->withHeader('Precognition-Validate-Only', 'fallbacks.1.iban')
        ->postJson('/checkout', checkoutData(['fallbacks' => [['type' => 'Card'], ['type' => 'bank']]]))
        ->assertStatus(422)
        ->assertExactJson([
            'message' => 'The fallbacks.1.iban field is required.',
            'errors' => ['fallbacks.1.iban' => ['The fallbacks.1.iban field is required.']],
        ]);
});
