<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Body;
use BYanelli\Roma\Request\Attributes\Header;
use BYanelli\Roma\Request\Attributes\Query;
use BYanelli\Roma\Response\Attributes\Header as ResponseHeader;
use BYanelli\Roma\Response\Attributes\Key;
use BYanelli\Roma\Response\Attributes\Optional;
use BYanelli\Roma\Response\Attributes\Status;
use BYanelli\Roma\Response\Response;
use BYanelli\Roma\TypeScript\Attributes\InputMapsToTypeScriptQuery;
use BYanelli\Roma\TypeScript\Attributes\TypeScriptName;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

enum TsRole: string
{
    case Admin = 'admin';
    case User = 'user';
}

enum TsPriority: int
{
    case Low = 1;
    case High = 2;
}

class TsAddressRequest
{
    public function __construct(
        public string $street,
        public ?string $unit = null,
    ) {}
}

class TsCreateUserRequest
{
    /**
     * @param  array<string>  $tags
     */
    public function __construct(
        public string $name,
        #[Body] public bool $active,
        #[Query] public int $page,
        #[Header('X-Api-Key')] public string $apiKey,
        #[InputMapsToTypeScriptQuery] public string $search,
        public TsRole $role,
        public array $tags,
        public TsAddressRequest $address,
        public ?int $age = null,
        public TsPriority $priority = TsPriority::Low,
    ) {}
}

class TsAddressResponse extends Response
{
    public function __construct(
        #[Key('street_name')] public string $street,
        public string $zip,
    ) {}
}

class TsUserResponse extends Response
{
    public function __construct(
        public string $name,
        #[Key('user_id')] public int $id,
        public TsAddressResponse $address,
        #[Optional] public ?string $nickname = null,
        #[Status] public int $status = 200,
        #[ResponseHeader('X-Rate-Limit')] public int $rateLimit = 60,
    ) {}
}

class TsDefaultedResponse extends Response
{
    public function __construct(
        public string $title,
        // A default (and a nullable) value always serializes, so the key is
        // always present: only #[Optional] makes a response field optional.
        public string $role = 'user',
        public ?string $bio = null,
        #[Optional] public ?string $note = null,
    ) {}
}

it('splits a request into Body, Query and Headers interfaces keyed by wire key', function () {
    $ts = new TypeScriptGenerator([TsCreateUserRequest::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface TsCreateUserRequestBody {
          name: string;
          active: boolean;
          role: TsRole;
          tags: string[];
          address: TsAddressRequest;
          age?: number | null;
          priority?: TsPriority;
        }
        TS)
        ->toContain(<<<'TS'
        export interface TsCreateUserRequestQuery {
          page: number;
          search: string;
        }
        TS)
        ->toContain(<<<'TS'
        export interface TsCreateUserRequestHeaders {
          'X-Api-Key': string;
        }
        TS);
});

#[TypeScriptName('KindEnum')]
enum TsKind: string
{
    case A = 'a';
    case B = 'b';
}

class TsNamedEnumRequest
{
    public function __construct(
        public TsKind $kind,
    ) {}
}

it('honors #[TypeScriptName] for the generated enum type name', function () {
    $ts = new TypeScriptGenerator([TsNamedEnumRequest::class])->generate();

    expect($ts)->toContain('export type KindEnum = typeof KindEnum[keyof typeof KindEnum];')
        ->toContain('kind: KindEnum;')
        ->not->toContain('TsKind');
});

it('emits a backed enum as a companion const plus a finite value-union type', function () {
    $ts = new TypeScriptGenerator([TsCreateUserRequest::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export const TsRole = {
          Admin: { name: 'Admin', value: 'admin' },
          User: { name: 'User', value: 'user' },
        } as const;

        export type TsRole = typeof TsRole[keyof typeof TsRole];
        TS)
        ->toContain(<<<'TS'
        export const TsPriority = {
          Low: { name: 'Low', value: 1 },
          High: { name: 'High', value: 2 },
        } as const;

        export type TsPriority = typeof TsPriority[keyof typeof TsPriority];
        TS);
});

it('emits a named interface for a nested request object', function () {
    $ts = new TypeScriptGenerator([TsCreateUserRequest::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface TsAddressRequest {
          street: string;
          unit?: string | null;
        }
        TS);
});

it('generates a response body interface keyed by output key, excluding lifted properties', function () {
    $ts = new TypeScriptGenerator([], [TsUserResponse::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface TsUserResponseBody {
          name: string;
          user_id: number;
          address: TsAddressResponse;
          nickname?: string | null;
        }
        TS)
        ->toContain(<<<'TS'
        export interface TsAddressResponse {
          street_name: string;
          zip: string;
        }
        TS)
        ->not->toContain('status')
        ->not->toContain('rateLimit');
});

it('emits a response Headers interface keyed by header name, with string values', function () {
    // Header values are strings on the wire and the client reads them back as
    // strings, so the field is `string` even though the property is an int.
    $ts = new TypeScriptGenerator([], [TsUserResponse::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface TsUserResponseHeaders {
          'X-Rate-Limit': string;
        }
        TS);
});

it('marks a response field optional only when #[Optional], not when it has a default or is nullable', function () {
    $ts = new TypeScriptGenerator([], [TsDefaultedResponse::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface TsDefaultedResponseBody {
          title: string;
          role: string;
          bio: string | null;
          note?: string | null;
        }
        TS);
});
