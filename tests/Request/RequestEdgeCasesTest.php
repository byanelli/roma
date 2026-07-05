<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Accessors\Ajax;
use BYanelli\Roma\Request\Attributes\Body;
use BYanelli\Roma\Request\Attributes\Present;
use BYanelli\Roma\Request\Attributes\Query;
use BYanelli\Roma\Request\Attributes\Rule;
use BYanelli\Roma\Request\ContextualBinding\ContextualBindingException;
use BYanelli\Roma\Request\ContextualBinding\Request as RequestAttribute;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;
use This\Class\Does\Not\Exist;

/*
|--------------------------------------------------------------------------
| Contextual binding (#[Request]) failure paths
|--------------------------------------------------------------------------
|
| The happy path is covered in RequestBindingTest. These exercise the
| defensive branches in ContextualBinding/Request::resolve() and the
| ContextualBindingException wrapper, which had no coverage.
*/

class EdgeBoundRequest
{
    public function __construct(public string $a = 'default') {}
}

it('throws when the #[Request] parameter is not type-hinted with a class', function () {
    /** @var TestCase $this */
    $this->setRequest();

    $func = fn (#[RequestAttribute] EdgeBoundRequest|string $request) => null;

    expect(fn () => $this->app->call($func))
        ->toThrow(ContextualBindingException::class, 'must be type-hinted with a class');
});

it('throws when the #[Request] parameter type-hints a non-existent class', function () {
    /** @var TestCase $this */
    $this->setRequest();

    $func = fn (#[RequestAttribute] Exist $request) => null;

    expect(fn () => $this->app->call($func))
        ->toThrow(ContextualBindingException::class, 'does not exist');
});

it('throws when the #[Request] parameter has no type hint', function () {
    /** @var TestCase $this */
    $this->setRequest();

    $func = fn (#[RequestAttribute] $request) => null;

    expect(fn () => $this->app->call($func))
        ->toThrow(ContextualBindingException::class, 'must be type-hinted with a class');
});

it('prefixes ContextualBindingException messages', function () {
    $e = new ContextualBindingException('boom');

    expect($e->getMessage())
        ->toBe('Error binding the request using the #[Request] attribute: boom');
});

/*
|--------------------------------------------------------------------------
| Query / Body source selection
|--------------------------------------------------------------------------
|
| Regression guard: Sources\Query::getOwnKey() previously returned 'header'
| (copy-paste bug), colliding with the Header bucket in flattenRequest.
*/

readonly class EdgeQuerySourceRequest
{
    #[Query]
    public string $value;
}

readonly class EdgeBodySourceRequest
{
    #[Body]
    public string $value;
}

it('reads a #[Query] property from the query string, not the body', function () {
    /** @var TestCase $this */
    $this->setRequest(
        query: ['value' => 'fromQuery'],
        headers: ['Content-Type' => 'application/json'],
        json: ['value' => 'fromBody'],
    );

    $request = $this->mapRequest(EdgeQuerySourceRequest::class);

    expect($request->value)->toBe('fromQuery');
});

it('reads a #[Body] property from the request body, not the query string', function () {
    /** @var TestCase $this */
    $this->setRequest(
        query: ['value' => 'fromQuery'],
        headers: ['Content-Type' => 'application/json'],
        json: ['value' => 'fromBody'],
    );

    $request = $this->mapRequest(EdgeBodySourceRequest::class);

    expect($request->value)->toBe('fromBody');
});

readonly class EdgeQueryIntRequest
{
    #[Query]
    public int $page;
}

it('keeps the source prefix in error keys', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['page' => 'notanint']);

    try {
        $this->mapRequest(EdgeQueryIntRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        // The origin is preserved so the caller knows where the value goes.
        expect($e->errors())->toHaveKey('query.page');
    }
});

/*
|--------------------------------------------------------------------------
| Coercion failure paths
|--------------------------------------------------------------------------
*/

readonly class EdgeIntRequest
{
    public int $count;
}

it('rejects a decimal string for an int property', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['count' => '1.5']);

    try {
        $this->mapRequest(EdgeIntRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.count');
    }
});

it('rejects a non-numeric string for an int property', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['count' => 'abc']);

    try {
        $this->mapRequest(EdgeIntRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.count');
    }
});

/*
|--------------------------------------------------------------------------
| Ajax rule matrix (mustBe: false / property-level null)
|--------------------------------------------------------------------------
*/

readonly class EdgeMustNotBeAjaxRequest
{
    #[Ajax(mustBe: false)]
    public bool $notAjax;
}

it('passes #[Ajax(mustBe: false)] for a non-ajax request', function () {
    /** @var TestCase $this */
    $this->setRequest();

    $request = $this->mapRequest(EdgeMustNotBeAjaxRequest::class);

    expect($request->notAjax)->toBeFalse();
});

it('fails #[Ajax(mustBe: false)] for an ajax request', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['X-Requested-With' => 'XMLHttpRequest']);

    expect(fn () => $this->mapRequest(EdgeMustNotBeAjaxRequest::class))
        ->toThrow(ValidationException::class);
});

readonly class EdgeOptionalAjaxRequest
{
    #[Ajax]
    public bool $isAjax;
}

it('applies no rule for a bare property-level #[Ajax]', function () {
    /** @var TestCase $this */
    $this->setRequest();

    // Not ajax and no `accepted` rule at property level, so this maps cleanly.
    $request = $this->mapRequest(EdgeOptionalAjaxRequest::class);

    expect($request->isAjax)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Mixed (untyped) and default values
|--------------------------------------------------------------------------
*/

class EdgeMixedRequest
{
    public $anything;
}

it('passes an untyped (mixed) property through untouched', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['anything' => 'hello']);

    $request = $this->mapRequest(EdgeMixedRequest::class);

    expect($request->anything)->toBe('hello');
});

class EdgeDefaultPropertyRequest
{
    public string $greeting = 'hi';
}

it('uses the default of a non-promoted class property when absent', function () {
    /** @var TestCase $this */
    $this->setRequest();

    $request = $this->mapRequest(EdgeDefaultPropertyRequest::class);

    expect($request->greeting)->toBe('hi');
});

/*
|--------------------------------------------------------------------------
| PhpDocTypeParser: constructor-param array branch + error paths
|--------------------------------------------------------------------------
*/

readonly class EdgeArrayCtorRequest
{
    /** @param array<int> $ids */
    public function __construct(public array $ids) {}
}

it('maps an array constructor parameter documented with @param', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['ids' => [1, 2, 3]],
    );

    $request = $this->mapRequest(EdgeArrayCtorRequest::class);

    expect($request->ids)->toBe([1, 2, 3]);
});

readonly class EdgeIntArrayRequest
{
    /** @var array<int> */
    public array $ids;
}

it('coerces string array elements to their documented int type', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['ids' => ['1', '2', '3']],
    );

    $request = $this->mapRequest(EdgeIntArrayRequest::class);

    // toBe is strict: proves the strings became ints, not just == equal.
    expect($request->ids)->toBe([1, 2, 3]);
});

readonly class EdgeArrayItem
{
    public string $label;
}

readonly class EdgeObjectArrayRequest
{
    /** @var array<EdgeArrayItem> */
    public array $items;
}

it('maps an array of nested objects', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['items' => [['label' => 'a'], ['label' => 'b']]],
    );

    $request = $this->mapRequest(EdgeObjectArrayRequest::class);

    expect($request->items)->toHaveCount(2)
        ->and($request->items[0])->toBeInstanceOf(EdgeArrayItem::class)
        ->and($request->items[0]->label)->toBe('a')
        ->and($request->items[1]->label)->toBe('b');
});

/*
|--------------------------------------------------------------------------
| Nested object validation
|--------------------------------------------------------------------------
*/

readonly class EdgeNestedChild
{
    #[Rule('email')]
    public string $email;
}

readonly class EdgeNestedParent
{
    public string $name;

    public EdgeNestedChild $child;
}

it('validates fields of a nested object', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['name' => 'Bill', 'child' => ['email' => 'not-an-email']],
    );

    try {
        $this->mapRequest(EdgeNestedParent::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.child.email');
    }
});

it('rejects a non-array value for a nested object', function () {
    /** @var TestCase $this */
    // A scalar where an object is expected must be a validation error, not a
    // TypeError bubbling out of construction.
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['name' => 'Bill', 'child' => 'notanobject'],
    );

    try {
        $this->mapRequest(EdgeNestedParent::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.child');
    }
});

it('passes a valid nested object', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['name' => 'Bill', 'child' => ['email' => 'bill@example.com']],
    );

    $request = $this->mapRequest(EdgeNestedParent::class);

    expect($request->child->email)->toBe('bill@example.com');
});

it('requires a missing required field on a nested object', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['name' => 'Bill', 'child' => []],
    );

    try {
        $this->mapRequest(EdgeNestedParent::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.child.email');
    }
});

readonly class EdgeValidatedItem
{
    #[Rule('min:3')]
    public string $code;
}

readonly class EdgeValidatedItemsRequest
{
    /** @var array<EdgeValidatedItem> */
    public array $items;
}

it('validates fields of objects inside an array', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['items' => [['code' => 'okay'], ['code' => 'x']]],
    );

    try {
        $this->mapRequest(EdgeValidatedItemsRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        // Only the second element is too short.
        expect($e->errors())->toHaveKey('input.items.1.code')
            ->and($e->errors())->not->toHaveKey('input.items.0.code');
    }
});

/*
|--------------------------------------------------------------------------
| Nullable semantics: ?T resolves to null when absent or null
|--------------------------------------------------------------------------
*/

readonly class EdgeNullableScalarRequest
{
    public string $name;

    public ?string $search;
}

it('resolves an absent nullable property to null', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['name' => 'Bill']);

    $request = $this->mapRequest(EdgeNullableScalarRequest::class);

    expect($request->search)->toBeNull();
});

it('resolves an explicit null nullable property to null', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['name' => 'Bill', 'search' => null],
    );

    $request = $this->mapRequest(EdgeNullableScalarRequest::class);

    expect($request->search)->toBeNull();
});

it('maps a present nullable property to its value', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['name' => 'Bill', 'search' => 'hats']);

    $request = $this->mapRequest(EdgeNullableScalarRequest::class);

    expect($request->search)->toBe('hats');
});

readonly class EdgePresentNullableRequest
{
    public string $name;

    #[Present]
    public ?string $note;
}

it('requires a #[Present] nullable key to exist', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['name' => 'Bill']); // note omitted

    try {
        $this->mapRequest(EdgePresentNullableRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.note');
    }
});

it('allows a #[Present] nullable key to be null', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['name' => 'Bill', 'note' => null],
    );

    $request = $this->mapRequest(EdgePresentNullableRequest::class);

    expect($request->note)->toBeNull();
});

readonly class EdgeNullableAddress
{
    public string $city;
}

readonly class EdgeNullableObjectRequest
{
    public string $name;

    public ?EdgeNullableAddress $address;
}

it('resolves an absent nullable object to null without requiring its children', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['name' => 'Bill'],
    );

    $request = $this->mapRequest(EdgeNullableObjectRequest::class);

    expect($request->address)->toBeNull();
});

it('maps a present nullable object and validates its children', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['name' => 'Bill', 'address' => ['city' => 'NYC']],
    );

    $request = $this->mapRequest(EdgeNullableObjectRequest::class);

    expect($request->address)->toBeInstanceOf(EdgeNullableAddress::class)
        ->and($request->address->city)->toBe('NYC');
});

it('validates required children of a present nullable object', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['name' => 'Bill', 'address' => ['zip' => '10001']],
    );

    try {
        $this->mapRequest(EdgeNullableObjectRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.address.city');
    }
});

it('treats a present empty object as present, not null', function () {
    /** @var TestCase $this */
    // An empty object is a provided object missing its required fields, so it
    // must error rather than resolve to null the way absent/null does.
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['name' => 'Bill', 'address' => []],
    );

    try {
        $this->mapRequest(EdgeNullableObjectRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.address.city');
    }
});

class EdgeUndocumentedArrayRequest
{
    public array $items;
}

it('throws when an array property has no @var docblock', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['items' => ['a', 'b']]);

    expect(fn () => $this->mapRequest(EdgeUndocumentedArrayRequest::class))
        ->toThrow(RuntimeException::class, 'must be documented by @var');
});
