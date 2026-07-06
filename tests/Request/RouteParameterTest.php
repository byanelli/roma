<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\RouteParameter;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

enum RouteRole: string
{
    case Admin = 'admin';
    case Member = 'member';
}

readonly class TestRouteStringParam
{
    #[RouteParameter]
    public string $slug;
}

it('maps a string route parameter', function () {
    /** @var TestCase $this */
    $this->setRequest(routeParams: ['slug' => 'hello-world']);

    expect($this->mapRequest(TestRouteStringParam::class)->slug)->toBe('hello-world');
});

readonly class TestRouteIntParam
{
    #[RouteParameter]
    public int $id;
}

it('coerces an int route parameter', function () {
    /** @var TestCase $this */
    $this->setRequest(routeParams: ['id' => '42']);

    expect($this->mapRequest(TestRouteIntParam::class)->id)->toBe(42);
});

readonly class TestRouteEnumParam
{
    #[RouteParameter]
    public RouteRole $role;
}

it('coerces an enum route parameter', function () {
    /** @var TestCase $this */
    $this->setRequest(routeParams: ['role' => 'admin']);

    expect($this->mapRequest(TestRouteEnumParam::class)->role)->toBe(RouteRole::Admin);
});

it('yields a required error for a missing route parameter', function () {
    /** @var TestCase $this */
    $this->setRequest(routeParams: ['somethingElse' => 'x']);

    try {
        $this->mapRequest(TestRouteIntParam::class);
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([
            'route.id' => ['The route.id field is required.'],
        ]);

        return;
    }

    $this->fail('Exception was not thrown');
});

it('yields a clean validation error when no route is bound', function () {
    /** @var TestCase $this */
    // No route resolver at all: the route bucket is empty, so the required
    // param fails validation cleanly rather than crashing.
    $this->app->bind('request', fn () => new Request);

    try {
        $this->mapRequest(TestRouteIntParam::class);
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([
            'route.id' => ['The route.id field is required.'],
        ]);

        return;
    }

    $this->fail('Exception was not thrown');
});

readonly class TestRouteExplicitKeyParam
{
    #[RouteParameter('user_id')]
    public int $userId;
}

it('maps a route parameter under an explicit key', function () {
    /** @var TestCase $this */
    $this->setRequest(routeParams: ['user_id' => '7']);

    expect($this->mapRequest(TestRouteExplicitKeyParam::class)->userId)->toBe(7);
});
