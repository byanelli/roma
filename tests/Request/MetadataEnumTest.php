<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Accessors\Method as MethodAccessor;
use BYanelli\Roma\Request\Attributes\Query;
use BYanelli\Roma\Request\Enums\ContentType;
use BYanelli\Roma\Request\Enums\Method;
use BYanelli\Roma\Request\Enums\Scheme;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Http\Request;

readonly class TestItInfersMethodEnumSource
{
    public Method $method;
}

it('infers the request method for a Method enum property', function () {
    /** @var TestCase $this */
    $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json']);
    $this->app->bind('request', fn () => $request);

    $mapped = $this->mapRequest(TestItInfersMethodEnumSource::class);

    $this->assertEquals(Method::Post, $mapped->method);
});

readonly class TestItInfersContentTypeEnumSource
{
    public ContentType $contentType;
}

it('infers the Content-Type header for a ContentType enum property', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: [
            'Content-Type' => 'application/json',
        ],
    );

    $mapped = $this->mapRequest(TestItInfersContentTypeEnumSource::class);

    $this->assertEquals(ContentType::Json, $mapped->contentType);
});

it('strips Content-Type parameters before matching the enum', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: [
            'Content-Type' => 'application/json; charset=utf-8',
        ],
    );

    $mapped = $this->mapRequest(TestItInfersContentTypeEnumSource::class);

    $this->assertEquals(ContentType::Json, $mapped->contentType);
});

it('maps newer media types like text/markdown and text/toon', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'text/markdown']);
    expect($this->mapRequest(TestItInfersContentTypeEnumSource::class)->contentType)
        ->toBe(ContentType::Markdown);

    $this->setRequest(headers: ['Content-Type' => 'text/toon']);
    expect($this->mapRequest(TestItInfersContentTypeEnumSource::class)->contentType)
        ->toBe(ContentType::Toon);
});

readonly class TestItInfersSchemeEnumSource
{
    public Scheme $scheme;
}

it('infers the URI scheme for a Scheme enum property', function () {
    /** @var TestCase $this */
    $this->app->bind('request', fn () => Request::create('https://example.com/'));

    expect($this->mapRequest(TestItInfersSchemeEnumSource::class)->scheme)
        ->toBe(Scheme::Https);

    $this->app->bind('request', fn () => Request::create('http://example.com/'));

    expect($this->mapRequest(TestItInfersSchemeEnumSource::class)->scheme)
        ->toBe(Scheme::Http);
});

readonly class TestExplicitSourceOverridesEnumInference
{
    #[Query]
    public Method $method;
}

it('lets an explicit source attribute override enum inference', function () {
    /** @var TestCase $this */
    // The request method is GET, but the explicit #[Query] source must win,
    // so the value comes from the query string, not $request->method().
    $request = Request::create('/', 'GET', ['method' => 'POST']);
    $this->app->bind('request', fn () => $request);

    $mapped = $this->mapRequest(TestExplicitSourceOverridesEnumInference::class);

    $this->assertEquals(Method::Post, $mapped->method);
});

readonly class TestMethodAttributeStillYieldsString
{
    #[MethodAccessor]
    public string $method;
}

it('keeps mapping an explicit #[Method] string property unchanged', function () {
    /** @var TestCase $this */
    $request = Request::create('/', 'DELETE');
    $this->app->bind('request', fn () => $request);

    $mapped = $this->mapRequest(TestMethodAttributeStillYieldsString::class);

    $this->assertSame('DELETE', $mapped->method);
});
