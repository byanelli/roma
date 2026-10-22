<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\SnakeCaseKeys;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\RomaHttp;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Body;
use BYanelli\Roma\Http\Attributes\Path;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Client\Attributes\Post;
use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\Tests\TestCase;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

readonly class SnakeAddress
{
    public function __construct(public string $postalCode) {}
}

#[SnakeCaseKeys]
readonly class SnakeLeadRequest
{
    public function __construct(
        public string $firstName,
        #[Key('surname')]
        public string $lastName,
        #[Path]
        public string $clientId,
        #[Query]
        public int $pageSize,
        public ?SnakeAddress $homeAddress = null,
    ) {}
}

readonly class SnakeChildRequest extends SnakeLeadRequest
{
    public ?string $middleName;
}

#[SnakeCaseKeys]
readonly class SnakeSettings
{
    public function __construct(public int $retryCount) {}
}

final readonly class SnakeEmpty {}

#[SnakeCaseKeys]
class SnakeResponse extends Response
{
    public function __construct(public int $leadId, #[Key('Kind')] public string $leadKind) {}
}

#[SnakeCaseKeys, Post('/clients/{client_id}/leads')]
final readonly class SnakeClientRequest
{
    public function __construct(public string $clientId, #[Body] public string $firstName) {}
}

function snakeLeadRequest(TestCase $test, string $class = SnakeLeadRequest::class): SnakeLeadRequest
{
    $test->setRequest(
        query: ['page_size' => '20', 'first_name' => 'Pat', 'surname' => 'Lee', 'home_address' => ['postalCode' => '35201'], 'middle_name' => 'J'],
        routeParams: ['client_id' => 'bsl'],
    );

    return $test->mapRequest($class);
}

it('maps properties from the snake_case of their names', function () {
    /** @var TestCase $this */
    expect(snakeLeadRequest($this))->toEqual(new SnakeLeadRequest('Pat', 'Lee', 'bsl', 20, new SnakeAddress('35201')));
});

it('applies to the properties of a subclass', function () {
    /** @var TestCase $this */
    expect(snakeLeadRequest($this, SnakeChildRequest::class)->middleName)->toBe('J');
});

it('keys errors by the snake_case keys', function () {
    /** @var TestCase $this */
    $this->setRequest(routeParams: ['client_id' => 'bsl']);

    try {
        $this->mapRequest(SnakeLeadRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['input.first_name', 'input.surname', 'query.page_size']);
    }
});

it('maps a plain array', function () {
    expect(Mapper::fromArray(SnakeSettings::class, ['retry_count' => '3'])->retryCount)->toBe(3);
});

it('serializes to the snake_case keys', function () {
    expect(Serializer::toArray(new SnakeResponse(7, 'driver')))->toBe(['lead_id' => 7, 'Kind' => 'driver']);
});

it('generates TypeScript with the snake_case keys', function () {
    $typescript = new TypeScriptGenerator(requests: [SnakeLeadRequest::class], responses: [SnakeResponse::class])->generate();

    expect($typescript)
        ->toContain('first_name: string;')
        ->toContain('page_size: number;')
        ->toContain('lead_id: number;')
        ->not->toContain('firstName');
});

it('fills a path parameter from the snake_case key in an outgoing request', function () {
    Http::preventStrayRequests();
    Http::fake(['api.test/*' => Http::response([])]);

    RomaHttp::baseUrl('https://api.test')->sendRequest(new SnakeClientRequest('bsl', 'Pat'), [SnakeEmpty::class]);

    [$request] = Http::recorded()->first();

    expect($request->url())->toBe('https://api.test/clients/bsl/leads')
        ->and(json_decode($request->body(), true))->toBe(['first_name' => 'Pat']);
});
