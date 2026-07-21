<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Enums\AuthScheme;
use BYanelli\Roma\Request\Values\Authorization;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

readonly class TestItInfersAuthorizationHeaderSource
{
    public Authorization $auth;
}

it('parses a Bearer Authorization header into a value object', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Authorization' => 'Bearer eyJhbGciOi.token']);

    $auth = $this->mapRequest(TestItInfersAuthorizationHeaderSource::class)->auth;

    expect($auth)->toBeInstanceOf(Authorization::class)
        ->and($auth->scheme)->toBe(AuthScheme::Bearer)
        ->and($auth->credentials)->toBe('eyJhbGciOi.token')
        ->and($auth->isBearer())->toBeTrue();
});

it('matches the auth scheme case-insensitively', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Authorization' => 'basic dXNlcjpwYXNz']);

    $auth = $this->mapRequest(TestItInfersAuthorizationHeaderSource::class)->auth;

    expect($auth->scheme)->toBe(AuthScheme::Basic)
        ->and($auth->credentials)->toBe('dXNlcjpwYXNz')
        ->and($auth->isBearer())->toBeFalse();
});

it('rejects an unknown auth scheme with a clean header-level error', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Authorization' => 'Weird sometoken']);

    try {
        $this->mapRequest(TestItInfersAuthorizationHeaderSource::class);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        // The error is reported against the header itself, not the value
        // object's internal scheme/credentials shape.
        expect(array_keys($e->errors()))
            ->toContain('header.Authorization')
            ->not->toContain('header.Authorization.scheme');
    }
});

it('rejects an Authorization header that has no credentials', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Authorization' => 'Bearer']);

    expect(fn () => $this->mapRequest(TestItInfersAuthorizationHeaderSource::class))
        ->toThrow(ValidationException::class);
});

it('requires the Authorization header when the property is non-nullable', function () {
    /** @var TestCase $this */
    $this->setRequest();

    expect(fn () => $this->mapRequest(TestItInfersAuthorizationHeaderSource::class))
        ->toThrow(ValidationException::class);
});

readonly class TestNullableAuthorizationHeaderSource
{
    public ?Authorization $auth;
}

it('leaves a nullable Authorization property null when the header is absent', function () {
    /** @var TestCase $this */
    $this->setRequest();

    expect($this->mapRequest(TestNullableAuthorizationHeaderSource::class)->auth)->toBeNull();
});
