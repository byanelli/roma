<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Request\Sources\Query as QuerySource;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Fails the test if anything decodes its JSON body.
 */
class JsonDecodeSpyRequest extends Request
{
    public function json($key = null, $default = null)
    {
        throw new LogicException('The JSON body was decoded.');
    }
}

readonly class QueryOnlyRequest
{
    public function __construct(
        #[Query]
        public int $page,
    ) {}
}

readonly class PaymentRequest
{
    public function __construct(
        public string $type,
        #[Rule('required_if:type,card')]
        public ?string $cardNumber = null,
        #[Rule('same:password')]
        public ?string $passwordConfirmation = null,
        public ?string $password = null,
    ) {}
}

readonly class CrossSourceRequest
{
    public function __construct(
        #[Header('X-Type')]
        public string $type,
        #[Query, Rule('required_if:type,card')]
        public ?string $cardNumber = null,
    ) {}
}

function spyRequest(string $uri): Request
{
    return JsonDecodeSpyRequest::create($uri, 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"page": 5}');
}

it('reads only the sources a class uses', function () {
    /** @var TestCase $this */
    $this->app->instance('request', spyRequest('/?page=2'));

    expect($this->mapRequest(QueryOnlyRequest::class)->page)->toBe(2);
});

it('decodes the JSON body for the default input source', function () {
    /** @var TestCase $this */
    $this->app->instance('request', spyRequest('/'));

    expect(fn () => $this->mapRequest(PaymentRequest::class))->toThrow(LogicException::class, 'The JSON body was decoded.');
});

it('applies a cross-field rule within a source by its relative key', function () {
    expect(Mapper::fromArray(PaymentRequest::class, ['type' => 'card', 'cardNumber' => '4242', 'password' => 'a', 'passwordConfirmation' => 'a'])->cardNumber)->toBe('4242')
        ->and(Mapper::fromArray(PaymentRequest::class, ['type' => 'cash'])->cardNumber)->toBeNull();

    expect(fn () => Mapper::fromArray(PaymentRequest::class, ['type' => 'card', 'password' => 'a', 'passwordConfirmation' => 'b']))
        ->toThrow(fn (MappingValidationException $e) => expect($e->errors())->toBe([
            'data.cardNumber' => ['The data.cardNumber field is required when data.type is card.'],
            'data.passwordConfirmation' => ['The data.passwordConfirmation field must match data.password.'],
        ]));
});

it('does not let a rule refer to another source', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['X-Type' => 'card']);

    // The query has no "type", so required_if passes although the header is "card".
    expect($this->mapRequest(CrossSourceRequest::class)->cardNumber)->toBeNull();

    // The query's own "type" applies, and the header's "cash" doesn't.
    $this->setRequest(query: ['type' => 'card'], headers: ['X-Type' => 'cash']);

    try {
        $this->mapRequest(CrossSourceRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['query.cardNumber' => ['The query.cardNumber field is required when type is card.']]);
    }
});

it('rejects a payload of another type when a source reads it', function () {
    expect(fn () => new QuerySource()->readFrom(new ArrayPayload([])))
        ->toThrow(RuntimeException::class, 'Roma source "query" reads from RequestPayload, but the payload is ArrayPayload.');
});
