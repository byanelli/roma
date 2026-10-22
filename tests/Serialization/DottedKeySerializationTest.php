<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Cookie;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Serialization\Profile;

enum DottedSerializationLevel: string
{
    case Low = 'low';
    case High = 'high';
}

class DottedSerializationTag
{
    public function __construct(
        #[Key('meta.label')]
        public string $label,
    ) {}
}

class DottedSerializationError
{
    /**
     * @param  list<DottedSerializationTag>  $tags
     */
    public function __construct(
        #[Key('error.message')]
        public string $message,
        #[Key('error.code')]
        public int $code,
        #[Key('error.level')]
        public DottedSerializationLevel $level,
        #[Key('error.detail')]
        public string|Missing $detail,
        #[Key('meta.trace.id')]
        public string|Missing $traceId,
        #[Key('created\.at')]
        public string $createdAt,
        #[Key('error.tag')]
        public DottedSerializationTag $tag,
        public array $tags = [],
        #[Key('auth.token')]
        #[SensitiveParameter]
        public string $token = 'secret',
    ) {}
}

class DottedSerializationResponse
{
    #[Key('error.hint')]
    #[Optional]
    public string $hint;

    public function __construct(
        #[Key('error.message')]
        public string $message,
        #[Header('X-Request.id')]
        public string $requestId,
        #[Status]
        public int $status = 422,
    ) {}
}

class DottedSerializationCookie
{
    public function __construct(
        #[Cookie('my.pref')]
        public string $pref,
    ) {}
}

class DottedSerializationConflict
{
    /**
     * @param  array<string, string>  $error
     */
    public function __construct(
        public array $error,
        #[Key('error.message')]
        public string $message,
    ) {}
}

function dottedSerializationError(): DottedSerializationError
{
    return new DottedSerializationError(
        message: 'Not found',
        code: 404,
        level: DottedSerializationLevel::High,
        detail: Missing::instance(),
        traceId: 'abc',
        createdAt: 'today',
        tag: new DottedSerializationTag('red'),
        tags: [new DottedSerializationTag('blue')],
    );
}

it('writes dotted keys under one parent as one nested object in all profiles', function (Profile $profile, mixed $level, array $sensitive) {
    expect(Serializer::toArray(dottedSerializationError(), $profile))->toBe([
        'error' => [
            'message' => 'Not found',
            'code' => 404,
            'level' => $level,
            'tag' => ['meta' => ['label' => 'red']],
        ],
        'meta' => ['trace' => ['id' => 'abc']],
        'created.at' => 'today',
        'tags' => [['meta' => ['label' => 'blue']]],
        ...$sensitive,
    ]);
})->with([
    'response' => [Profile::response(), ['name' => 'High', 'value' => 'high'], []],
    'client request' => [Profile::clientRequest(), 'high', ['auth' => ['token' => 'secret']]],
    'storage' => [Profile::storage(), 'high', []],
]);

it('leaves no parent behind when all of its values are absent or sensitive', function () {
    $error = dottedSerializationError();
    $error->traceId = Missing::instance();

    expect(Serializer::toArray($error))->not->toHaveKeys(['meta', 'auth']);
});

it('maps a serialized object with dotted keys back to an equal object', function () {
    $error = dottedSerializationError();

    expect(Mapper::fromArray(DottedSerializationError::class, Serializer::toArray($error, Profile::storage())))->toEqual($error);
});

it('keeps a dot in header and cookie keys, and nests the body beside them', function () {
    $response = new DottedSerializationResponse('Invalid', 'abc');
    $serialized = Serializer::serialize($response);

    expect($serialized->body)->toBe(['error' => ['message' => 'Invalid']])
        ->and($serialized->headers)->toBe(['X-Request.id' => 'abc'])
        ->and($serialized->status)->toBe(422);

    $response->hint = 'Try again';

    expect(Serializer::toArray($response))->toBe(['error' => ['hint' => 'Try again', 'message' => 'Invalid']])
        ->and(Serializer::toArray(new DottedSerializationCookie('dark')))->toBe(['my.pref' => 'dark']);
});

it('rejects a property written inside another property\'s value', function () {
    expect(fn () => Serializer::toArray(new DottedSerializationConflict(['code' => 'x'], 'Invalid')))->toThrow(
        RuntimeException::class,
        'Roma class DottedSerializationConflict writes $error to "error" and $message to "error.message", so one would replace the other. Give one of them another key.',
    );
});
