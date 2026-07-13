<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\ContextualBinding\Request;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Contracts\Container\BindingResolutionException;

#[Request]
class DisabledInjectRequest
{
    public function __construct(
        public string $a,
        public string $b,
    ) {}
}

it('does not auto-resolve a class-marked request when auto_inject is disabled', function () {
    /** @var TestCase $this */
    config()->set('roma.auto_inject', false);

    $this->setRequest(query: ['a' => 'foo', 'b' => 'bar']);

    // With auto-injection off, no binding is registered, so the container falls
    // back to autowiring and fails on the unresolvable primitive constructor
    // parameters.
    expect(fn () => $this->app->make(DisabledInjectRequest::class))
        ->toThrow(BindingResolutionException::class);
});
