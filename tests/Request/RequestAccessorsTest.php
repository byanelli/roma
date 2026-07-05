<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Accessors\Host;
use BYanelli\Roma\Request\Attributes\Accessors\Ips;
use BYanelli\Roma\Request\Attributes\Accessors\IsJson;
use BYanelli\Roma\Request\Attributes\Accessors\Path;
use BYanelli\Roma\Request\Attributes\Accessors\Secure;
use BYanelli\Roma\Request\Attributes\Accessors\Segments;
use BYanelli\Roma\Request\Attributes\Accessors\Url;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

readonly class TestStringAccessors
{
    #[Host]
    public string $host;

    #[Path]
    public string $path;

    #[Url]
    public string $url;
}

it('maps string accessors', function () {
    /** @var TestCase $this */
    $this->app->bind('request', fn () => Request::create('http://example.com/foo/bar'));

    $request = $this->mapRequest(TestStringAccessors::class);

    $this->assertSame('example.com', $request->host);
    $this->assertSame('foo/bar', $request->path);
    $this->assertSame('http://example.com/foo/bar', $request->url);
});

readonly class TestArrayAccessors
{
    /** @var array<string> */
    #[Segments]
    public array $segments;

    /** @var array<string> */
    #[Ips]
    public array $ips;
}

it('maps array accessors', function () {
    /** @var TestCase $this */
    $this->app->bind('request', fn () => Request::create('http://example.com/foo/bar'));

    $request = $this->mapRequest(TestArrayAccessors::class);

    $this->assertSame(['foo', 'bar'], $request->segments);
    $this->assertSame(['127.0.0.1'], $request->ips);
});

readonly class TestBooleanAccessors
{
    #[Secure]
    public bool $secure;

    #[IsJson]
    public bool $isJson;
}

it('maps boolean accessors', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: [
            'Content-Type' => 'application/json',
        ],
    );

    $request = $this->mapRequest(TestBooleanAccessors::class);

    $this->assertFalse($request->secure);
    $this->assertTrue($request->isJson);
});

readonly class TestRequiresSecure
{
    #[Secure(mustBe: true)]
    public bool $secure;
}

it('passes mustBe on a boolean accessor when satisfied', function () {
    /** @var TestCase $this */
    $this->app->bind('request', fn () => Request::create('https://example.com/'));

    $request = $this->mapRequest(TestRequiresSecure::class);

    $this->assertTrue($request->secure);
});

it('fails mustBe on a boolean accessor when not satisfied', function () {
    /** @var TestCase $this */
    $this->app->bind('request', fn () => Request::create('http://example.com/'));

    try {
        $this->mapRequest(TestRequiresSecure::class);
    } catch (ValidationException $e) {
        $this->assertEquals(
            ['request.secure' => ['The request.secure field must be accepted.']],
            $e->errors()
        );

        return;
    }

    $this->assertTrue(false, 'Exception was not thrown');
});
