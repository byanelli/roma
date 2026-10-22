<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\Tests\Support\Recursive\CommentResponse;
use BYanelli\Roma\Tests\Support\Recursive\Thread;
use BYanelli\Roma\TypeScript\Attributes\TypeScriptName;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

class TsSharedProfile
{
    public function __construct(
        public string $name,
        public string $role = 'user',
    ) {}
}

class TsSharedCredentials
{
    public function __construct(
        public string $user,
        #[SensitiveParameter]
        public string $password,
    ) {}
}

class TsSharedTag
{
    public function __construct(
        public string $label,
    ) {}
}

class TsSharedTeam
{
    public function __construct(
        public string $name,
        public TsSharedProfile $lead,
    ) {}
}

class TsSharedTree
{
    /**
     * @param  list<TsSharedTree>  $children
     */
    public function __construct(
        public string $name,
        public array $children,
    ) {}
}

#[TypeScriptName('Login')]
class TsSharedLogin
{
    public function __construct(
        public string $user,
        #[SensitiveParameter]
        public string $password,
    ) {}
}

class TsSharedRequest
{
    public function __construct(
        public TsSharedProfile $profile,
        public TsSharedCredentials $credentials,
        public TsSharedTag $tag,
        public TsSharedTeam $team,
        public TsSharedTree $tree,
        public TsSharedLogin $login,
    ) {}
}

class TsSharedResponse extends Response
{
    public function __construct(
        public TsSharedProfile $profile,
        public TsSharedCredentials $credentials,
        public TsSharedTag $tag,
        public TsSharedTeam $team,
        public TsSharedTree $tree,
        public TsSharedLogin $login,
    ) {}
}

class TsTeamOnlyResponse extends Response
{
    public function __construct(
        public TsSharedProfile $profile,
    ) {}
}

class TsThreadResponse extends Response
{
    public function __construct(
        public Thread $thread,
    ) {}
}

function sharedTypeScript(): string
{
    return new TypeScriptGenerator([TsSharedRequest::class], [TsSharedResponse::class])->generate();
}

it('gives a shared class a response variant when a default makes a request property optional', function () {
    expect(sharedTypeScript())
        ->toContain(<<<'TS'
            export interface TsSharedProfile {
              name: string;
              role?: string;
            }
            TS)
        ->toContain(<<<'TS'
            export interface TsSharedProfileResponse {
              name: string;
              role: string;
            }
            TS)
        ->toContain(<<<'TS'
            export interface TsSharedRequestBody {
              profile: TsSharedProfile;
            TS)
        ->toContain(<<<'TS'
            export interface TsSharedResponseBody {
              profile: TsSharedProfileResponse;
            TS);
});

it('gives a shared class a response variant without its #[\SensitiveParameter] properties', function () {
    expect(sharedTypeScript())
        ->toContain(<<<'TS'
            export interface TsSharedCredentials {
              user: string;
              password: string;
            }
            TS)
        ->toContain(<<<'TS'
            export interface TsSharedCredentialsResponse {
              user: string;
            }
            TS)
        ->toContain('  credentials: TsSharedCredentialsResponse;');
});

it('emits one interface under the class name when the request and response types are the same', function () {
    $ts = sharedTypeScript();

    expect($ts)
        ->toContain(<<<'TS'
            export interface TsSharedTag {
              label: string;
            }
            TS)
        ->not->toContain('TsSharedTagResponse')
        ->and(substr_count($ts, 'tag: TsSharedTag;'))->toBe(2);
});

it('gives a response variant to a class that contains a class with one', function () {
    expect(sharedTypeScript())
        ->toContain(<<<'TS'
            export interface TsSharedTeam {
              name: string;
              lead: TsSharedProfile;
            }
            TS)
        ->toContain(<<<'TS'
            export interface TsSharedTeamResponse {
              name: string;
              lead: TsSharedProfileResponse;
            }
            TS);
});

it('keeps the class name for a class used in responses only', function () {
    expect(new TypeScriptGenerator([], [TsTeamOnlyResponse::class])->generate())
        ->toContain(<<<'TS'
            export interface TsSharedProfile {
              name: string;
              role: string;
            }
            TS)
        ->not->toContain('TsSharedProfileResponse');
});

it('emits one interface for a self-referencing class that is the same in both directions', function () {
    $ts = sharedTypeScript();

    expect($ts)
        ->toContain(<<<'TS'
            export interface TsSharedTree {
              name: string;
              children: TsSharedTree[];
            }
            TS)
        ->not->toContain('TsSharedTreeResponse');
});

it('names the response variant after a #[TypeScriptName]', function () {
    expect(sharedTypeScript())
        ->toContain(<<<'TS'
            export interface Login {
              user: string;
              password: string;
            }
            TS)
        ->toContain(<<<'TS'
            export interface LoginResponse {
              user: string;
            }
            TS)
        ->not->toContain('TsSharedLogin');
});

it('gives self-referencing classes and union members response variants that refer to each other', function () {
    $ts = new TypeScriptGenerator([Thread::class], [TsThreadResponse::class])->generate();

    expect($ts)
        ->toContain(<<<'TS'
            export interface Comment {
              body: string;
              likes?: number;
              replies?: Comment[];
            }
            TS)
        ->toContain(<<<'TS'
            export interface CommentResponse {
              body: string;
              likes: number;
              replies: CommentResponse[];
            }
            TS)
        ->toContain(<<<'TS'
            export interface PersonResponse {
              name: string;
              pet: PetResponse | null;
            }
            TS)
        ->toContain(<<<'TS'
            export interface PetResponse {
              name: string;
              owner: PersonResponse | null;
            }
            TS)
        ->toContain("export type Entry = ({ type: 'Note' } & Note) | ({ type: 'Folder' } & Folder);")
        ->toContain("export type EntryResponse = ({ type: 'Note' } & Note) | ({ type: 'Folder' } & FolderResponse);")
        ->toContain(<<<'TS'
            export interface FolderResponse {
              name: string;
              children: EntryResponse[];
            }
            TS)
        // Thread is nested in the response only, so it keeps its name.
        ->toContain(<<<'TS'
            export interface Thread {
              title: string;
              replies: CommentResponse[];
              node: NodeResponse | null;
              root: EntryResponse | null;
              person: PersonResponse | null;
            }
            TS)
        ->not->toContain('NoteResponse');
});

it('suffixes a response variant whose name another class already has', function (array $responses) {
    $ts = new TypeScriptGenerator([Thread::class], $responses)->generate();

    expect($ts)
        ->toContain(<<<'TS'
            export interface CommentResponse {
              body: string;
              replies: CommentResponse[];
            }
            TS)
        ->toContain(<<<'TS'
            export interface CommentResponse2 {
              body: string;
              likes: number;
              replies: CommentResponse2[];
            }
            TS)
        ->toContain('  replies: CommentResponse2[];')
        ->and(substr_count($ts, 'export interface CommentResponse '))->toBe(1);
})->with([
    'class first' => [[CommentResponse::class, TsThreadResponse::class]],
    'variant first' => [[TsThreadResponse::class, CommentResponse::class]],
]);
