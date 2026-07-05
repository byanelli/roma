<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Accessors\Ajax;
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
