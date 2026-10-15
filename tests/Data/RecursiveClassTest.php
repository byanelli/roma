<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\Tests\Support\Recursive\BadEntry;
use BYanelli\Roma\Tests\Support\Recursive\Comment;
use BYanelli\Roma\Tests\Support\Recursive\CommentResponse;
use BYanelli\Roma\Tests\Support\Recursive\Folder;
use BYanelli\Roma\Tests\Support\Recursive\Node;
use BYanelli\Roma\Tests\Support\Recursive\Note;
use BYanelli\Roma\Tests\Support\Recursive\Person;
use BYanelli\Roma\Tests\Support\Recursive\Pet;
use BYanelli\Roma\Tests\Support\Recursive\Thread;
use BYanelli\Roma\Tests\TestCase;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;
use Illuminate\Validation\ValidationException;

function mapRecursive(array $data): Thread
{
    return Mapper::fromArray(Thread::class, $data);
}

/**
 * @return array<string, list<string>>
 */
function recursiveErrors(Closure $map): array
{
    try {
        $map();
    } catch (ValidationException|MappingValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a validation exception');
}

function threadData(): array
{
    return [
        'title' => 'Recursion',
        'replies' => [
            ['body' => 'a', 'replies' => [
                ['body' => 'a.0', 'likes' => '2'],
                ['body' => 'a.1', 'replies' => [['body' => 'a.1.0']]],
            ]],
            ['body' => 'b'],
        ],
        'node' => ['name' => 'leaf', 'parent' => ['name' => 'branch', 'parent' => ['name' => 'root']]],
        'root' => ['type' => 'Folder', 'name' => '/', 'children' => [
            ['type' => 'Note', 'name' => 'readme', 'size' => '10'],
            ['type' => 'Folder', 'name' => 'src', 'children' => [['type' => 'Note', 'name' => 'main', 'size' => '20']]],
        ]],
        'person' => ['name' => 'Bill', 'pet' => ['name' => 'Rex', 'owner' => ['name' => 'Ann']]],
    ];
}

function expectedThread(): Thread
{
    return new Thread(
        'Recursion',
        [
            new Comment('a', replies: [new Comment('a.0', 2), new Comment('a.1', replies: [new Comment('a.1.0')])]),
            new Comment('b'),
        ],
        new Node('leaf', new Node('branch', new Node('root'))),
        new Folder('/', [new Note('readme', 10), new Folder('src', [new Note('main', 20)])]),
        new Person('Bill', new Pet('Rex', new Person('Ann'))),
    );
}

it('maps classes that contain themselves from a plain array', function () {
    expect(mapRecursive(threadData()))->toEqual(expectedThread());
});

it('maps classes that contain themselves from a JSON body', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: threadData());

    expect($this->mapRequest(Thread::class))->toEqual(expectedThread());
});

it('reports errors at depth under concrete, source-prefixed keys', function () {
    /** @var TestCase $this */
    $data = threadData();
    unset($data['replies'][0]['replies'][1]['body']);
    $data['replies'][0]['replies'][1]['replies'][0]['likes'] = 'many';
    unset($data['node']['parent']['parent']['name']);
    $data['root']['children'][1]['children'][0]['type'] = 'Link';
    unset($data['person']['pet']['owner']['name']);

    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: $data);

    expect(recursiveErrors(fn () => $this->mapRequest(Thread::class)))->toBe([
        'input.replies.0.replies.1.body' => ['The input.replies.0.replies.1.body field is required.'],
        'input.replies.0.replies.1.replies.0.likes' => ['The input.replies.0.replies.1.replies.0.likes field must be an integer.'],
        'input.node.parent.parent.name' => ['The input.node.parent.parent.name field is required.'],
        'input.root.children.1.children.0.type' => ['The input.root.children.1.children.0.type field must be one of: Note, Folder.'],
        'input.person.pet.owner.name' => ['The input.person.pet.owner.name field is required.'],
    ]);
});

it('maps a top-level class that contains itself', function () {
    $map = fn (array $data) => Mapper::fromArray(Node::class, $data);

    expect($map(['name' => 'a', 'parent' => ['name' => 'b', 'parent' => ['name' => 'c']]]))->toEqual(new Node('a', new Node('b', new Node('c'))))
        ->and(recursiveErrors(fn () => $map(['name' => 'a', 'parent' => ['name' => 'b', 'parent' => []]])))
        ->toBe(['data.parent.parent.name' => ['The data.parent.parent.name field is required.']]);
});

it('checks the discriminator key against a member that contains its own union', function () {
    $holder = new class
    {
        public BadEntry $entry;
    };

    Mapper::fromArray($holder::class, []);
})->throws(RuntimeException::class, 'Roma property "BadFolder::$name" uses the key "name"');

it('builds rules for a class that contains itself only as deep as the data', function () {
    $mapping = app(ObjectMapper::class)->mapping(Thread::class, new ArrayPayload(['title' => 't', 'replies' => [['body' => 'a', 'replies' => [['body' => 'b']]]]]), new ArrayPolicy);

    expect(array_keys($mapping->validationRules()[0]->toArray()))->toBe([
        'title',
        'replies',
        'replies.0',
        'replies.0.body',
        'replies.0.likes',
        'replies.0.replies',
        'replies.0.replies.0',
        'replies.0.replies.0.body',
        'replies.0.replies.0.likes',
        'replies.0.replies.0.replies',
        'node',
        'root',
        'root.type',
        'person',
    ]);
});

it('serializes classes that contain themselves', function () {
    expect(Serializer::toArray(expectedThread(), Profile::storage()))->toBe([
        'title' => 'Recursion',
        'replies' => [
            ['body' => 'a', 'likes' => 0, 'replies' => [
                ['body' => 'a.0', 'likes' => 2, 'replies' => []],
                ['body' => 'a.1', 'likes' => 0, 'replies' => [['body' => 'a.1.0', 'likes' => 0, 'replies' => []]]],
            ]],
            ['body' => 'b', 'likes' => 0, 'replies' => []],
        ],
        'node' => ['name' => 'leaf', 'parent' => ['name' => 'branch', 'parent' => ['name' => 'root', 'parent' => null]]],
        'root' => ['type' => 'Folder', 'name' => '/', 'children' => [
            ['type' => 'Note', 'name' => 'readme', 'size' => 10],
            ['type' => 'Folder', 'name' => 'src', 'children' => [['type' => 'Note', 'name' => 'main', 'size' => 20]]],
        ]],
        'person' => ['name' => 'Bill', 'pet' => ['name' => 'Rex', 'owner' => ['name' => 'Ann', 'pet' => null]]],
    ])
        ->and(mapRecursive(Serializer::toArray(expectedThread(), Profile::storage())))->toEqual(expectedThread());
});

it('emits a TypeScript interface that refers to itself by name', function () {
    $ts = new TypeScriptGenerator([Thread::class])->generate();

    expect($ts)
        ->toContain(<<<'TS'
            export interface Comment {
              body: string;
              likes?: number;
              replies?: Comment[];
            }
            TS)
        ->toContain(<<<'TS'
            export interface Node {
              name: string;
              parent?: Node | null;
            }
            TS)
        ->toContain(<<<'TS'
            export interface Folder {
              name: string;
              children?: Entry[];
            }
            TS)
        ->toContain("export type Entry = ({ type: 'Note' } & Note) | ({ type: 'Folder' } & Folder);")
        ->toContain(<<<'TS'
            export interface Pet {
              name: string;
              owner?: Person | null;
            }
            TS)
        ->and(substr_count($ts, 'export interface Comment '))->toBe(1);
});

it('names the top-level response interface apart from the nested one it contains', function () {
    expect(new TypeScriptGenerator([], [CommentResponse::class])->generate())
        ->toContain(<<<'TS'
            export interface CommentResponse {
              body: string;
              replies: CommentResponse[];
            }
            TS)
        ->toContain(<<<'TS'
            export interface CommentResponseBody {
              body: string;
              replies: CommentResponse[];
            }
            TS);
});
