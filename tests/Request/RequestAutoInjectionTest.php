<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\ContextualBinding\Request;
use BYanelli\Roma\Tests\TestCase;

#[Request]
class ClassMarkedRequest
{
    public function __construct(
        public string $a,
        public string $b,
    ) {}
}

it('resolves a class-level #[Request] by type-hint alone', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['a' => 'foo', 'b' => 'bar']);

    $request = $this->app->make(ClassMarkedRequest::class);

    expect($request)->toBeInstanceOf(ClassMarkedRequest::class)
        ->and($request->a)->toBe('foo')
        ->and($request->b)->toBe('bar');
});

it('injects a class-level #[Request] into a callable without the parameter hint', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['a' => 'foo', 'b' => 'bar']);

    $func = function (ClassMarkedRequest $request) {
        expect($request->a)->toBe('foo')
            ->and($request->b)->toBe('bar');
    };

    $this->app->call($func);
});

it('still honours the parameter-level #[Request] hint on a class-marked request', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['a' => 'foo', 'b' => 'bar']);

    $func = function (#[Request] ClassMarkedRequest $request) {
        expect($request->a)->toBe('foo');
    };

    $this->app->call($func);
});
