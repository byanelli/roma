<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\Serialization\IsArrayable;
use BYanelli\Roma\Serialization\Profile;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;

readonly class SensitiveCredentials
{
    public function __construct(
        public string $user,
        #[SensitiveParameter] public string $password,
    ) {}
}

readonly class SensitiveAccount
{
    /**
     * @param  list<SensitiveCredentials>  $fallbacks
     */
    public function __construct(
        public string $name,
        #[SensitiveParameter] public string $apiKey,
        public SensitiveCredentials $primary,
        public array $fallbacks,
    ) {}
}

class SensitiveArrayable implements Arrayable, JsonSerializable
{
    use IsArrayable;

    public function __construct(
        public string $name,
        #[SensitiveParameter] public string $token,
    ) {}
}

class SensitiveResponse extends Response
{
    public function __construct(
        public string $name,
        #[SensitiveParameter] public string $token,
        #[Header('X-Token'), SensitiveParameter] public string $headerToken,
        #[Header('X-Request-Id')] public string $requestId = 'r1',
    ) {}
}

class SensitiveAssigned
{
    public string $name;

    public string $token;

    public function __construct(string $name, #[SensitiveParameter] string $token)
    {
        $this->name = $name;
        $this->token = trim($token);
    }
}

function sensitiveAccount(): SensitiveAccount
{
    return new SensitiveAccount(
        name: 'main',
        apiKey: 'key_1',
        primary: new SensitiveCredentials('ox', 'secret'),
        fallbacks: [new SensitiveCredentials('di', 'hunter2')],
    );
}

it('leaves #[\SensitiveParameter] properties out of nested objects and arrays in all profiles', function (Profile $profile) {
    expect(Serializer::toArray(sensitiveAccount(), $profile))->toBe([
        'name' => 'main',
        'primary' => ['user' => 'ox'],
        'fallbacks' => [['user' => 'di']],
    ]);
})->with([
    'response' => fn () => Profile::response(),
    'client request' => fn () => Profile::clientRequest(),
    'storage' => fn () => Profile::storage(),
]);

it('leaves #[\SensitiveParameter] properties out of toArray() and JSON', function () {
    $object = new SensitiveArrayable('main', 'key_1');

    expect($object->toArray())->toBe(['name' => 'main'])
        ->and(json_encode($object))->toBe('{"name":"main"}');
});

it('leaves #[\SensitiveParameter] properties out of a response body and headers', function () {
    $response = new SensitiveResponse('main', 'key_1', 'key_2')->toResponse(new Request);

    expect($response->getData(true))->toBe(['name' => 'main'])
        ->and($response->headers->has('X-Token'))->toBeFalse()
        ->and($response->headers->get('X-Request-Id'))->toBe('r1');
});

it('leaves out a property assigned from a #[\SensitiveParameter] parameter of the same name', function () {
    expect(Serializer::toArray(new SensitiveAssigned('main', ' key_1 ')))->toBe(['name' => 'main']);
});
