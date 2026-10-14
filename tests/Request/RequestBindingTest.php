<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Http\Request\Injection\Request;
use BYanelli\Roma\Tests\TestCase;

class TestBoundRequest
{
    public function __construct(
        public string $a,
        public string $b,
    ) {}
}

it('binds the request contextually', function () {
    /** @var TestCase $this */
    $this->setRequest(
        query: [
            'a' => 'foo',
            'b' => 'bar',
        ],
    );

    $func = function (#[Request] TestBoundRequest $request) {
        $this->assertEquals('foo', $request->a);
        $this->assertEquals('bar', $request->b);
    };

    $this->app->call($func);
});
