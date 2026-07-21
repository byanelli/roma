<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Enums\AuthScheme;
use BYanelli\Roma\Request\Values\Authorization;
use BYanelli\Roma\Request\Values\BasicCredentials;
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

it('decodes Basic credentials from a mapped request end to end', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Authorization' => 'Basic '.base64_encode('aladdin:opensesame')]);

    $basic = $this->mapRequest(TestItInfersAuthorizationHeaderSource::class)->auth->basic();

    expect($basic)->toEqual(new BasicCredentials('aladdin', 'opensesame'));
});

it('splits Basic credentials on the first colon so the password may contain colons', function () {
    $auth = new Authorization(AuthScheme::Basic, base64_encode('user:p:a:ss'));

    expect($auth->basic())->toEqual(new BasicCredentials('user', 'p:a:ss'));
});

it('decodes Basic credentials with an empty password', function () {
    $auth = new Authorization(AuthScheme::Basic, base64_encode('user:'));

    expect($auth->basic())->toEqual(new BasicCredentials('user', ''));
});

it('returns null Basic credentials when the scheme is not Basic', function () {
    $auth = new Authorization(AuthScheme::Bearer, base64_encode('user:pass'));

    expect($auth->basic())->toBeNull();
});

it('returns null Basic credentials when the base64 is invalid', function () {
    $auth = new Authorization(AuthScheme::Basic, 'not valid base64!!');

    expect($auth->basic())->toBeNull();
});

it('returns null Basic credentials when the decoded value has no colon', function () {
    $auth = new Authorization(AuthScheme::Basic, base64_encode('nocolonhere'));

    expect($auth->basic())->toBeNull();
});
