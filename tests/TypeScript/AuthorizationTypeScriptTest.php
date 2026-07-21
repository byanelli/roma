<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Body;
use BYanelli\Roma\Request\Values\Authorization;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

class TsAuthRequest
{
    public function __construct(
        public string $name,
        public Authorization $auth,
    ) {}
}

class TsAuthBodyRequest
{
    public function __construct(
        public string $name,
        #[Body] public Authorization $auth,
    ) {}
}

it('renders an Authorization value object as a string in the Headers interface', function () {
    $ts = new TypeScriptGenerator([TsAuthRequest::class])->generate();

    // The header is a plain string on the wire, not the {scheme, credentials}
    // value object it hydrates into server-side.
    expect($ts)->toContain(<<<'TS'
        export interface TsAuthRequestHeaders {
          Authorization: string;
        }
        TS);

    expectNoAuthorizationShapeLeak($ts);
});

it('renders a string-parsed value object as a string outside the Headers bucket too', function () {
    // A value object sourced from the body must not rely on header interfaces
    // being forced to string values — it parses from a string on the wire, so it
    // is a string wherever it appears.
    $ts = new TypeScriptGenerator([TsAuthBodyRequest::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface TsAuthBodyRequestBody {
          name: string;
          auth: string;
        }
        TS);

    expectNoAuthorizationShapeLeak($ts);
});

function expectNoAuthorizationShapeLeak(string $ts): void
{
    // The value object's internal shape must never leak into the emitted types.
    expect($ts)
        ->not->toContain('scheme')
        ->not->toContain('credentials')
        ->not->toContain('AuthScheme')
        ->not->toContain('interface Authorization');
}
