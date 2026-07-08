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
          role: 'admin' | 'user';
          tags: string[];
          address: TsAddressRequest;
          age?: number | null;
          priority?: 1 | 2;
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

it('emits a named interface for a nested request object', function () {
    $ts = new TypeScriptGenerator([TsCreateUserRequest::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface TsAddressRequest {
          street: string;
          unit?: string | null;
        }
        TS);
});

it('generates one interface per response, keyed by output key, excluding lifted properties', function () {
    $ts = new TypeScriptGenerator([], [TsUserResponse::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface TsUserResponse {
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

it('marks a response field optional only when #[Optional], not when it has a default or is nullable', function () {
    $ts = new TypeScriptGenerator([], [TsDefaultedResponse::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface TsDefaultedResponse {
          title: string;
          role: string;
          bio: string | null;
          note?: string | null;
        }
        TS);
});
