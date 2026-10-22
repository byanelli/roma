<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\Missing;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

class TsDottedShared
{
    public function __construct(
        #[Key('error.message')]
        public string $message,
        #[Key('error.code')]
        public int $code = 0,
    ) {}
}

class TsDottedRequest
{
    public function __construct(
        #[Key('error.message')]
        public string $message,
        #[Key('created\.at')]
        public string $createdAt,
        #[Header('X-Client.version')]
        public string $clientVersion,
        #[Query('filter.status')]
        public string $status,
        #[Query('filter.tags')]
        public string $tags,
        public TsDottedShared $shared,
        #[Key('error.code')]
        public int $code = 0,
        #[Key('meta.trace.id')]
        public ?string $traceId = null,
    ) {}
}

class TsDottedResponse extends Response
{
    public function __construct(
        #[Key('error.message')]
        public string $message,
        #[Key('error.detail')]
        public string|Missing $detail,
        #[Key('meta.trace.id')]
        #[Optional]
        public ?string $traceId,
        #[Header('X-Request.id')]
        public string $requestId,
        public TsDottedShared $shared,
        #[Key('auth.token')]
        #[SensitiveParameter]
        public string $token = '',
        #[Status]
        public int $status = 200,
    ) {}
}

function dottedTypeScript(): string
{
    return new TypeScriptGenerator([TsDottedRequest::class], [TsDottedResponse::class])->generate();
}

it('merges dotted request keys under one parent into one inline object', function () {
    expect(dottedTypeScript())
        ->toContain(<<<'TS'
            export interface TsDottedRequestBody {
              error: { message: string; code?: number };
              'created.at': string;
              shared: TsDottedShared;
              meta?: { trace?: { id?: string | null } };
            }
            TS)
        ->toContain(<<<'TS'
            export interface TsDottedRequestQuery {
              filter: { status: string; tags: string };
            }
            TS)
        ->toContain(<<<'TS'
            export interface TsDottedRequestHeaders {
              'X-Client.version': string;
            }
            TS);
});

it('marks a response parent optional only when all of its members are', function () {
    expect(dottedTypeScript())
        ->toContain(<<<'TS'
            export interface TsDottedResponseBody {
              error: { message: string; detail?: string };
              meta?: { trace?: { id?: string | null } };
              shared: TsDottedSharedResponse;
            }
            TS)
        ->toContain(<<<'TS'
            export interface TsDottedResponseHeaders {
              'X-Request.id': string;
            }
            TS);
});

it('gives a shared class with dotted keys a response variant when its types differ', function () {
    expect(dottedTypeScript())
        ->toContain(<<<'TS'
            export interface TsDottedShared {
              error: { message: string; code?: number };
            }
            TS)
        ->toContain(<<<'TS'
            export interface TsDottedSharedResponse {
              error: { message: string; code: number };
            }
            TS);
});
