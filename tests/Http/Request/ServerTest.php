<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\RejectUnknownKeys;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Server;
use BYanelli\Roma\Tests\TestCase;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

readonly class ServerRequiredRequest
{
    public function __construct(
        #[Server('SERVER_NAME')]
        public string $serverName,
    ) {}
}

readonly class ServerOptionalRequest
{
    public function __construct(
        #[Server('SERVER_NAME')]
        public ?string $serverName = null,

        #[Server('REQUEST_TIME_FLOAT')]
        public float $startedAt = 1.5,
    ) {}
}

readonly class ServerDottedRequest
{
    public function __construct(
        #[Server('app.region')]
        public string $region,
    ) {}
}

#[RejectUnknownKeys]
readonly class ServerStrictRequest
{
    public function __construct(
        #[Server('SERVER_NAME')]
        public string $serverName,
    ) {}
}

readonly class ServerEmptyKeyRequest
{
    public function __construct(
        #[Server('')]
        public string $value,
    ) {}
}

readonly class ServerNestedMember
{
    public function __construct(
        #[Server('SERVER_NAME')]
        public string $serverName,
    ) {}
}

readonly class ServerNestedRequest
{
    public function __construct(
        public ServerNestedMember $member,
    ) {}
}

it('maps a variable as $request->server() returns it', function () {
    /** @var TestCase $this */
    $this->setRequest(serverVariables: ['SERVER_NAME' => 'example.test']);

    $mapped = $this->mapRequest(ServerRequiredRequest::class);

    expect($mapped->serverName)->toBe('example.test')
        ->and($mapped->serverName)->toBe(app('request')->server('SERVER_NAME'));
});

it('coerces a variable to the property type', function () {
    /** @var TestCase $this */
    $this->setRequest(serverVariables: ['REQUEST_TIME_FLOAT' => '1700000000.25']);

    expect($this->mapRequest(ServerOptionalRequest::class)->startedAt)->toBe(1700000000.25);
});

it('uses null or the default for an absent variable', function () {
    /** @var TestCase $this */
    $this->setRequest();

    $mapped = $this->mapRequest(ServerOptionalRequest::class);

    expect($mapped->serverName)->toBeNull()
        ->and($mapped->startedAt)->toBe(1.5);
});

it('reports an absent required variable under server.KEY', function () {
    /** @var TestCase $this */
    $this->setRequest();

    try {
        $this->mapRequest(ServerRequiredRequest::class);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([
            'server.SERVER_NAME' => ['The server.SERVER_NAME field is required.'],
        ]);
    }
});

it('keeps the server prefix with source_prefixed_errors off', function () {
    /** @var TestCase $this */
    config(['roma.source_prefixed_errors' => false]);
    $this->setRequest();

    try {
        $this->mapRequest(ServerRequiredRequest::class);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['server.SERVER_NAME']);
    }
});

it('compares keys exactly, including case', function () {
    /** @var TestCase $this */
    $this->setRequest(serverVariables: ['server_name' => 'lowercase.test']);

    expect($this->mapRequest(ServerOptionalRequest::class)->serverName)->toBeNull();
});

it('keeps a dot as part of the key', function () {
    /** @var TestCase $this */
    $this->setRequest(serverVariables: ['app.region' => 'east', 'app' => ['region' => 'west']]);

    expect($this->mapRequest(ServerDottedRequest::class)->region)->toBe('east');
});

it('writes a dotted key as one key when serializing', function () {
    expect(Serializer::toArray(new ServerDottedRequest('east')))->toBe(['app.region' => 'east']);
});

// Server variables always include keys no property declares.
it('does not treat other variables as unknown keys', function () {
    /** @var TestCase $this */
    $this->setRequest(serverVariables: ['SERVER_NAME' => 'example.test', 'REMOTE_ADDR' => '127.0.0.1']);

    expect($this->mapRequest(ServerStrictRequest::class)->serverName)->toBe('example.test');
});

it('throws for an empty key', function () {
    /** @var TestCase $this */
    $this->setRequest();

    $this->mapRequest(ServerEmptyKeyRequest::class);
})->throws(RuntimeException::class);

it('throws in a nested object', function () {
    /** @var TestCase $this */
    $this->setRequest(serverVariables: ['SERVER_NAME' => 'example.test']);

    $this->mapRequest(ServerNestedRequest::class);
})->throws(RuntimeException::class, 'source');

it('throws when mapping from an array', function () {
    Mapper::fromArray(ServerRequiredRequest::class, ['SERVER_NAME' => 'example.test']);
})->throws(RuntimeException::class, 'maps from the "server" source');

it('throws when mapping only the body', function () {
    /** @var TestCase $this */
    $this->setRequest(serverVariables: ['SERVER_NAME' => 'example.test']);

    $this->getRequestMapper()->mapBody(ServerRequiredRequest::class);
})->throws(RuntimeException::class, 'maps from the "server" source');

it('leaves the variable out of the TypeScript request interfaces', function () {
    $typescript = new TypeScriptGenerator([ServerRequiredRequest::class], [])->generate();

    expect($typescript)->not->toContain('SERVER_NAME')
        ->and($typescript)->not->toContain('serverName');
});

it('reads the request it is given rather than $_SERVER', function () {
    /** @var TestCase $this */
    $original = $_SERVER['SERVER_NAME'] ?? null;
    $_SERVER['SERVER_NAME'] = 'global.test';
    $this->app->bind('request', fn () => new Request(server: ['SERVER_NAME' => 'bag.test']));

    try {
        expect($this->mapRequest(ServerRequiredRequest::class)->serverName)->toBe('bag.test');
    } finally {
        if ($original === null) {
            unset($_SERVER['SERVER_NAME']);
        } else {
            $_SERVER['SERVER_NAME'] = $original;
        }
    }
});
