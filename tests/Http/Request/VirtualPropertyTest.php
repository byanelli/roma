<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\DateFormat;
use BYanelli\Roma\Attributes\Description;
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\Message;
use BYanelli\Roma\Attributes\Present;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Accessors\Ip;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Enums\Method;
use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\Tests\TestCase;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

class VirtualPropRequest
{
    public string $first;

    public string $last;

    // A computed (virtual) property: no backing store, get-only.
    public string $fullName {
        get => $this->first.' '.$this->last;
    }
}

class VirtualPropResponse extends Response
{
    public function __construct(
        public string $first,
        public string $last,
    ) {}

    public string $fullName {
        get => $this->first.' '.$this->last;
    }
}

it('does not treat a computed property as a request input', function () {
    /** @var TestCase $this */
    // Only the real inputs are sent — the computed property is not required.
    $this->setRequest(query: ['first' => 'Ada', 'last' => 'Lovelace']);

    $request = $this->mapRequest(VirtualPropRequest::class);

    expect($request->first)->toBe('Ada')
        ->and($request->last)->toBe('Lovelace')
        ->and($request->fullName)->toBe('Ada Lovelace'); // still computes normally
});

it('excludes a computed property from a generated request interface', function () {
    $typescript = new TypeScriptGenerator(requests: [VirtualPropRequest::class])->generate();

    expect($typescript)
        ->toContain('export interface VirtualPropRequestBody {')
        ->toContain('first: string;')
        ->toContain('last: string;')
        ->not->toContain('fullName');
});

it('keeps a computed property in a generated response interface', function () {
    // Responses serialize computed properties, so the generated type must too.
    $typescript = new TypeScriptGenerator(responses: [VirtualPropResponse::class])->generate();

    expect($typescript)
        ->toContain('export interface VirtualPropResponseBody {')
        ->toContain('fullName: string;');
});

class VirtualWithRule
{
    public string $first = 'Ada';

    #[Rule('max:10')]
    public string $upper {
        get => strtoupper($this->first);
    }
}

class VirtualWithPresent
{
    public string $first = 'Ada';

    #[Present]
    public ?string $nickname {
        get => null;
    }
}

class VirtualWithMessage
{
    public string $first = 'Ada';

    #[Message(required: 'Send a name.')]
    public string $upper {
        get => strtoupper($this->first);
    }
}

class VirtualWithAccessor
{
    #[Ip]
    public string $ip {
        get => '127.0.0.1';
    }
}

it('rejects a mapping attribute on a virtual property', function (string $class, string $attribute) {
    /** @var TestCase $this */
    $this->setRequest(query: ['first' => 'Ada']);
    $message = "Roma property \"{$class}::\$";

    expect(fn () => $this->mapRequest($class))
        ->toThrow(RuntimeException::class, "is virtual (a hooked property with no backing value), so it is never mapped and #[{$attribute}] has no effect")
        ->and(fn () => new TypeScriptGenerator(requests: [$class])->generate())->toThrow(RuntimeException::class, $message)
        ->and(fn () => Serializer::toArray(new $class))->toThrow(RuntimeException::class, "#[{$attribute}] has no effect");
})->with([
    [VirtualWithRule::class, 'Rule'],
    [VirtualWithPresent::class, 'Present'],
    [VirtualWithAccessor::class, 'Ip'],
    [VirtualWithMessage::class, 'Message'],
]);

class VirtualWithOutputAttributes extends Response
{
    public function __construct(public string $first, public DateTimeImmutable $at) {}

    #[Key('display_name')]
    #[Description('The name to show.')]
    public string $displayName {
        get => strtoupper($this->first);
    }

    #[Header('X-Day')]
    public string $day {
        get => $this->at->format('l');
    }

    #[DateFormat('Y-m-d')]
    public DateTimeImmutable $date {
        get => $this->at;
    }
}

it('allows attributes that affect only serialization and TypeScript on a virtual property', function () {
    $response = new VirtualWithOutputAttributes('Ada', new DateTimeImmutable('2024-05-01 09:00:00'));
    $serialized = Serializer::serialize($response);

    expect($serialized->body)->toBe(['first' => 'Ada', 'at' => '2024-05-01T09:00:00+00:00', 'display_name' => 'ADA', 'date' => '2024-05-01'])
        ->and($serialized->headers)->toBe(['X-Day' => 'Wednesday'])
        ->and(new TypeScriptGenerator(responses: [VirtualWithOutputAttributes::class])->generate())
        ->toContain('display_name: string;')
        ->toContain('The name to show.');
});

class VirtualMethodResponse extends Response
{
    public function __construct(public string $path) {}

    public Method $method {
        get => Method::Get;
    }
}

// The type implies a source attribute, which the virtual check doesn't count.
it('accepts a computed property whose type implies a source', function () {
    $typescript = new TypeScriptGenerator(responses: [VirtualMethodResponse::class])->generate();

    expect($typescript)->toContain('export interface VirtualMethodResponseBody {')
        ->and(Serializer::toArray(new VirtualMethodResponse('/a')))->toBe(['path' => '/a', 'method' => ['name' => 'Get', 'value' => 'GET']]);
});
