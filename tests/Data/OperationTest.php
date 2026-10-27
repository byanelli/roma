<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Attributes\OptionalOnUpdate;
use BYanelli\Roma\Attributes\RequiredOnCreate;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Request\Injection\Request as RomaRequest;
use BYanelli\Roma\Http\Request\RequestSourcePolicy;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

readonly class OperationContact
{
    public function __construct(
        #[RequiredOnCreate]
        public string|Missing $email,

        #[OptionalOnUpdate]
        public string|Missing $name,

        #[OptionalOnUpdate]
        public string|Missing|null $phone,

        public string|Missing $note,
    ) {}
}

#[RomaRequest]
readonly class OperationContactRequest
{
    public function __construct(
        #[OptionalOnUpdate]
        public string|Missing $name,
    ) {}
}

readonly class OnUpdateWithoutMissing
{
    public function __construct(
        #[OptionalOnUpdate]
        public ?string $name,
    ) {}
}

readonly class OnUpdateWithDefault
{
    public function __construct(
        #[OptionalOnUpdate]
        public string|Missing $name = 'Ada',
    ) {}
}

readonly class OnUpdateWithOptional
{
    public function __construct(
        #[OptionalOnUpdate]
        #[Optional]
        public string|Missing $name,
    ) {}
}

readonly class OnUpdateAndOnCreate
{
    public function __construct(
        #[OptionalOnUpdate]
        #[RequiredOnCreate]
        public string|Missing $name,
    ) {}
}

function operationErrors(array $data, Operation $operation): array
{
    try {
        Mapper::fromArray(OperationContact::class, $data, $operation);
    } catch (MappingValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

describe('the request policy', function () {
    it('maps the method to an operation', function (string $method, Operation $operation) {
        expect(new RequestSourcePolicy()->operation(Request::create('/', $method)))->toBe($operation);
    })->with([
        ['POST', Operation::Create],
        ['PUT', Operation::Update],
        ['PATCH', Operation::Update],
        ['GET', Operation::None],
        ['DELETE', Operation::None],
    ]);

    it('uses a form\'s _method override', function (string $override, Operation $operation) {
        Request::enableHttpMethodParameterOverride();

        expect(new RequestSourcePolicy()->operation(Request::create('/', 'POST', ['_method' => $override])))->toBe($operation);
    })->with([
        ['PUT', Operation::Update],
        ['PATCH', Operation::Update],
        ['DELETE', Operation::None],
    ]);
});

describe('Mapper::fromArray()', function () {
    it('requires #[RequiredOnCreate] and #[OptionalOnUpdate] properties in a Create', function () {
        expect(operationErrors([], Operation::Create))->toBe(['data.email', 'data.name', 'data.phone']);
    });

    it('requires only #[OptionalOnUpdate] properties without an operation', function () {
        expect(operationErrors([], Operation::None))->toBe(['data.name', 'data.phone']);

        expect(fn () => Mapper::fromArray(OperationContact::class, []))->toThrow(MappingValidationException::class);
    });

    it('requires neither in an Update', function () {
        $contact = Mapper::fromArray(OperationContact::class, [], Operation::Update);

        expect($contact->email)->toBe(Missing::instance())
            ->and($contact->name)->toBe(Missing::instance())
            ->and($contact->phone)->toBe(Missing::instance())
            ->and(Serializer::toArray($contact))->toBe([]);
    });

    it('maps a present #[OptionalOnUpdate] value in an Update', function () {
        $contact = Mapper::fromArray(OperationContact::class, ['name' => 'Ada', 'phone' => null], Operation::Update);

        expect($contact->name)->toBe('Ada')
            ->and($contact->phone)->toBeNull()
            ->and(Serializer::toArray($contact))->toBe(['name' => 'Ada', 'phone' => null]);
    });

    it('accepts null for a nullable #[OptionalOnUpdate] property only in an Update', function () {
        expect(operationErrors(['name' => 'Ada', 'phone' => null], Operation::None))->toBe(['data.phone'])
            ->and(operationErrors(['name' => 'Ada', 'phone' => null], Operation::Update))->toBe([]);
    });

    it('maps a complete array in each operation', function (Operation $operation) {
        $contact = Mapper::fromArray(OperationContact::class, ['email' => 'a@example.com', 'name' => 'Ada', 'phone' => '555'], $operation);

        expect($contact->note)->toBe(Missing::instance());
    })->with([Operation::Create, Operation::Update, Operation::None]);
});

describe('a request', function () {
    beforeEach(function () {
        $serialize = fn (OperationContactRequest $request) => Serializer::toArray($request);

        Route::post('/contacts', $serialize);
        Route::put('/contacts', $serialize);
        Route::patch('/contacts', $serialize);
        Route::get('/contacts', $serialize);
    });

    it('leaves #[OptionalOnUpdate] properties optional on PUT and PATCH', function (string $method) {
        /** @var TestCase $this */
        $this->json($method, '/contacts', [])->assertOk()->assertExactJson([]);
    })->with(['PUT', 'PATCH']);

    it('requires #[OptionalOnUpdate] properties on other methods', function (string $method) {
        /** @var TestCase $this */
        $this->json($method, '/contacts', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['input.name' => ['The input.name field is required.']]);
    })->with(['POST', 'GET']);

    it('treats a POST with a PUT override as an update', function () {
        /** @var TestCase $this */
        $this->postJson('/contacts', ['_method' => 'PUT'])->assertOk();
    });
});

describe('the class definition', function () {
    it('rejects #[OptionalOnUpdate] on an invalid property', function (string $class, string $message) {
        expect(fn () => Mapper::fromArray($class, []))->toThrow(RuntimeException::class, $message);
    })->with([
        'without Missing' => [OnUpdateWithoutMissing::class, 'Roma property "OnUpdateWithoutMissing::$name" has #[OptionalOnUpdate], but its type has no Missing'],
        'with a default' => [OnUpdateWithDefault::class, 'Roma property "OnUpdateWithDefault::$name" has #[OptionalOnUpdate], but it has a default'],
        'with #[Optional]' => [OnUpdateWithOptional::class, 'Roma property "OnUpdateWithOptional::$name" has #[OptionalOnUpdate] and #[Optional]'],
        'with #[RequiredOnCreate]' => [OnUpdateAndOnCreate::class, 'Roma property "OnUpdateAndOnCreate::$name" has #[OptionalOnUpdate] and #[RequiredOnCreate]'],
    ]);
});
