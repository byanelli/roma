<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Http\Attributes\Body;
use BYanelli\Roma\Http\Attributes\Cookie;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Path;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

readonly class DottedError
{
    public function __construct(
        #[Key('error.message')] public string $message,
        #[Key('error.code')] public int $code,
        #[Key('error.detail')] public string|Missing $detail,
        #[Key('created\.at')] public string $createdAt,
        #[Key('meta.trace.id')] public ?string $traceId = null,
    ) {}
}

readonly class DottedChild
{
    public function __construct(#[Key('meta.id')] public int $id) {}
}

readonly class DottedParent
{
    /**
     * @param  list<DottedChild>  $children
     */
    public function __construct(
        public DottedChild $child,
        public array $children = [],
    ) {}
}

readonly class DottedMixedRequest
{
    public function __construct(
        #[Body('error.message')] public string $message,
        #[Query('filter.status')] public string $status,
        #[Header('X-Client.version')] public string $clientVersion,
        #[Cookie('my.pref')] public string $pref,
        #[Path('user.id')] public string $userId,
    ) {}
}

readonly class DottedAcrossSourcesRequest
{
    public function __construct(
        #[Query('error')] public string $error,
        #[Body('error.message')] public string $message,
    ) {}
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, list<string>>
 */
function dottedErrors(string $class, array $data): array
{
    try {
        Mapper::fromArray($class, $data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('No validation error.');
}

it('reads a dotted key from nested arrays in a plain array', function () {
    $error = Mapper::fromArray(DottedError::class, [
        'error' => ['message' => 'Not found', 'code' => '404'],
        'created.at' => 'today',
        'meta' => ['trace' => ['id' => 'abc']],
    ]);

    expect($error->message)->toBe('Not found')
        ->and($error->code)->toBe(404)
        ->and($error->detail)->toBe(Missing::instance())
        ->and($error->createdAt)->toBe('today')
        ->and($error->traceId)->toBe('abc');
});

it('does not read a nested key from a literal dotted key, or the reverse', function () {
    expect(dottedErrors(DottedError::class, [
        'error.message' => 'Not found',
        'error.code' => 404,
        'created' => ['at' => 'today'],
    ]))->toBe([
        'data.error.message' => ['The data.error.message field is required.'],
        'data.error.code' => ['The data.error.code field is required.'],
        'data.created.at' => ['The data.created.at field is required.'],
    ]);
});

it('reports errors under the full dotted path', function () {
    expect(dottedErrors(DottedError::class, [
        'error' => ['message' => 'Not found', 'code' => 'x'],
        'created.at' => 5,
        'meta' => ['trace' => ['id' => []]],
    ]))->toBe([
        'data.error.code' => ['The data.error.code field must be an integer.'],
        'data.created.at' => ['The data.created.at field must be a string.'],
        'data.meta.trace.id' => ['The data.meta.trace.id field must be a string.'],
    ]);
});

it('requires a dotted key when its parent is not an array', function () {
    expect(dottedErrors(DottedError::class, ['error' => 'boom', 'created.at' => 'today']))->toBe([
        'data.error.message' => ['The data.error.message field is required.'],
        'data.error.code' => ['The data.error.code field is required.'],
    ]);
});

it('nests a dotted key inside a nested object and an array of them', function () {
    $parent = Mapper::fromArray(DottedParent::class, [
        'child' => ['meta' => ['id' => 1]],
        'children' => [['meta' => ['id' => 2]], ['meta' => ['id' => 3]]],
    ]);

    expect($parent->child->id)->toBe(1)
        ->and(array_map(fn (DottedChild $child) => $child->id, $parent->children))->toBe([2, 3])
        ->and(dottedErrors(DottedParent::class, ['child' => ['meta' => []], 'children' => [['meta' => ['id' => 'x']]]]))->toBe([
            'data.child.meta.id' => ['The data.child.meta.id field is required.'],
            'data.children.0.meta.id' => ['The data.children.0.meta.id field must be an integer.'],
        ]);
});

it('nests dots in body and query keys and keeps them in header, cookie and path keys', function () {
    /** @var TestCase $this */
    $this->setRequest(
        query: ['filter' => ['status' => 'open']],
        headers: ['Content-Type' => 'application/json', 'X-Client.version' => '2.1'],
        json: ['error' => ['message' => 'boom']],
        routeParams: ['user.id' => '7'],
        cookies: ['my.pref' => 'dark'],
    );

    $request = $this->mapRequest(DottedMixedRequest::class);

    expect($request->message)->toBe('boom')
        ->and($request->status)->toBe('open')
        ->and($request->clientVersion)->toBe('2.1')
        ->and($request->pref)->toBe('dark')
        ->and($request->userId)->toBe('7');
});

it('reports a request\'s dotted keys with their source prefix', function () {
    /** @var TestCase $this */
    $this->setRequest(
        query: ['filter.status' => 'open'],
        headers: ['Content-Type' => 'application/json'],
        json: ['error.message' => 'boom'],
    );

    try {
        $this->mapRequest(DottedMixedRequest::class);
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe([
            'body.error.message',
            'query.filter.status',
            'header.X-Client.version',
            'cookie.my.pref',
            'path.user.id',
        ]);

        return;
    }

    $this->fail('Exception was not thrown');
});

it('allows a key in one source beside a key inside it in another', function () {
    /** @var TestCase $this */
    $this->setRequest(
        query: ['error' => 'yes'],
        headers: ['Content-Type' => 'application/json'],
        json: ['error' => ['message' => 'boom']],
    );

    $request = $this->mapRequest(DottedAcrossSourcesRequest::class);

    expect($request->error)->toBe('yes')
        ->and($request->message)->toBe('boom');
});

readonly class DottedParentConflict
{
    /**
     * @param  array<string, string>  $error
     */
    public function __construct(
        #[Key('error.message')] public string $message,
        public array $error,
    ) {}
}

readonly class DottedSamePathConflict
{
    public function __construct(
        #[Key('error.message')] public string $message,
        #[Key('error.message')] public string $text,
    ) {}
}

readonly class DottedEscapedConflict
{
    public function __construct(
        #[Key('created\.at')] public string $literal,
        #[Key('created.at')] public string $nested,
    ) {}
}

readonly class DottedNestedConflictChild
{
    public function __construct(
        #[Key('meta')] public string $meta,
        #[Key('meta.id')] public int $id,
    ) {}
}

readonly class DottedNestedConflict
{
    public function __construct(public DottedNestedConflictChild $child) {}
}

readonly class DottedEmptySegment
{
    public function __construct(#[Key('error..message')] public string $message) {}
}

it('rejects conflicting dotted keys when the definition is built', function (string $class, string $message) {
    expect(fn () => Mapper::fromArray($class, []))->toThrow(RuntimeException::class, $message);
})->with([
    'a key inside another' => [
        DottedParentConflict::class,
        'Roma class DottedParentConflict maps $message from "data.error.message", which is inside the value of $error at "data.error". Give one of them another key.',
    ],
    'the same path' => [
        DottedSamePathConflict::class,
        'Roma class DottedSamePathConflict maps $message and $text from the same key "data.error.message". Give one of them another key.',
    ],
    'an escaped and a nested key with the same error key' => [
        DottedEscapedConflict::class,
        'Roma class DottedEscapedConflict maps $literal and $nested from the same key "data.created.at". Give one of them another key.',
    ],
    'a nested object' => [
        DottedNestedConflict::class,
        'Roma class DottedNestedConflictChild maps $id from "meta.id", which is inside the value of $meta at "meta". Give one of them another key.',
    ],
    'an empty segment' => [
        DottedEmptySegment::class,
        'Roma key "error..message" (property "message") has an empty segment. A dot separates nested keys; write a literal dot as "\.".',
    ],
]);

final readonly class DottedCard
{
    public function __construct(public string $last4) {}
}

final readonly class DottedVoucher
{
    public function __construct(#[Key('type.code')] public string $code) {}
}

readonly class DottedDiscriminatorKey
{
    public function __construct(
        #[Discriminated([DottedCard::class, DottedVoucher::class], key: 'meta.type')] public DottedCard|DottedVoucher $payment,
    ) {}
}

readonly class DottedUnderDiscriminatorKey
{
    public function __construct(
        #[Discriminated([DottedCard::class, DottedVoucher::class])] public DottedCard|DottedVoucher $payment,
    ) {}
}

it('rejects a dot in a discriminator key', function () {
    expect(fn () => Mapper::fromArray(DottedDiscriminatorKey::class, []))->toThrow(
        RuntimeException::class,
        'has the key "meta.type". A discriminator key is a key at the top of the object and may not contain a dot.',
    );
});

it('rejects a member key inside the discriminator key', function () {
    expect(fn () => Mapper::fromArray(DottedUnderDiscriminatorKey::class, []))->toThrow(
        RuntimeException::class,
        'Roma property "DottedVoucher::$code" uses the key "type", which is the discriminator key of a union the class belongs to.',
    );
});
