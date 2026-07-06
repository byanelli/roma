<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Cookie;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

readonly class TestCookieStringValue
{
    #[Cookie]
    public string $session;
}

it('maps a string cookie', function () {
    /** @var TestCase $this */
    $this->setRequest(cookies: ['session' => 'abc123']);

    expect($this->mapRequest(TestCookieStringValue::class)->session)->toBe('abc123');
});

readonly class TestCookieCoercedValues
{
    #[Cookie]
    public bool $darkMode;

    #[Cookie]
    public int $visits;
}

it('coerces bool and int cookies', function () {
    /** @var TestCase $this */
    $this->setRequest(cookies: ['darkMode' => 'true', 'visits' => '3']);

    $mapped = $this->mapRequest(TestCookieCoercedValues::class);

    expect($mapped->darkMode)->toBeTrue();
    expect($mapped->visits)->toBe(3);
});

it('yields a required error for a missing cookie', function () {
    /** @var TestCase $this */
    $this->setRequest(cookies: ['other' => 'x']);

    try {
        $this->mapRequest(TestCookieStringValue::class);
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([
            'cookie.session' => ['The cookie.session field is required.'],
        ]);

        return;
    }

    $this->fail('Exception was not thrown');
});

readonly class TestDottedCookieName
{
    #[Cookie('my.pref')]
    public string $pref;
}

it('maps a cookie whose name contains a literal dot', function () {
    /** @var TestCase $this */
    $this->setRequest(cookies: ['my.pref' => 'compact']);

    expect($this->mapRequest(TestDottedCookieName::class)->pref)->toBe('compact');
});
