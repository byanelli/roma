<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Accessors\Ajax;
use BYanelli\Roma\Request\Attributes\Body;
use BYanelli\Roma\Request\Attributes\Query;
use BYanelli\Roma\Request\ContextualBinding\ContextualBindingException;
use BYanelli\Roma\Request\ContextualBinding\Request as RequestAttribute;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

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

    $func = fn (#[RequestAttribute] \This\Class\Does\Not\Exist $request) => null;

    expect(fn () => $this->app->call($func))
        ->toThrow(ContextualBindingException::class, 'does not exist');
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
