<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;
use Illuminate\Http\Request;

#[Status(201)]
class ClassStatusCreated extends Response
{
    public string $name = 'Bill';
}

#[Status(201)]
class ClassStatusOverridden extends Response
{
    #[Status]
    public int $status = 202;
}

#[Status('2xx')]
class ClassStatusRange extends Response {}

it('sends a response with its class-level #[Status]', function () {
    $response = new ClassStatusCreated()->toResponse(new Request);

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getData(true))->toBe(['name' => 'Bill']);
});

it('prefers the #[Status] property to the class-level #[Status]', function () {
    expect(new ClassStatusOverridden()->toResponse(new Request)->getStatusCode())->toBe(202);
});

it('rejects a class-level #[Status] without a code on a server response', function () {
    expect(fn () => new ClassStatusRange()->toResponse(new Request))
        ->toThrow(RuntimeException::class, 'needs exactly one, with a code such as 201');
});

it('normalizes a status', function (int|string $code, string $pattern) {
    expect(new Status($code)->pattern)->toBe($pattern);
})->with([
    [404, '404'],
    ['404', '404'],
    ['4XX', '4xx'],
    ['default', 'default'],
]);

it('rejects a status that is not a code, a range or default', function (int|string $code, string $message) {
    expect(fn () => new Status($code))->toThrow(InvalidArgumentException::class, $message);
})->with([
    [600, 'Roma #[Status(600)] is not a status'],
    ['6xx', "Roma #[Status('6xx')] is not a status"],
    ['other', "Roma #[Status('other')] is not a status"],
]);

class PropertyStatusPromotedCode
{
    public function __construct(
        #[Status(404)]
        public int $status = 200,
    ) {}
}

class PropertyStatusDeclaredCode
{
    #[Status(404)]
    public int $status = 200;
}

class PropertyStatusParameterCode
{
    public function __construct(
        #[Status(404)]
        int $status = 200,
    ) {}
}

class PropertyStatusRange
{
    public function __construct(
        #[Status('4xx')]
        public int $status = 200,
    ) {}
}

class PropertyStatusDefault
{
    public function __construct(
        #[Status('default')]
        public int $status = 200,
    ) {}
}

class PropertyStatusCodeOnResponse extends Response
{
    #[Status(404)]
    public int $status = 200;
}

class PropertyStatusNestedCode
{
    public function __construct(
        public PropertyStatusPromotedCode $nested,
    ) {}
}

#[Status(201)]
class PropertyStatusBare extends Response
{
    public function __construct(
        #[Status]
        public int $status = 202,
    ) {}
}

class PropertyStatusBareWithoutDefault extends Response
{
    public function __construct(
        #[Status]
        public int $status,
    ) {}
}

it('rejects a code in a property-level #[Status] when the definition is built', function (string $class, string $property, string $given) {
    expect(fn () => Mapper::fromArray($class, []))->toThrow(
        RuntimeException::class,
        "Roma property \"{$class}::\${$property}\" has #[Status({$given})], but a code has meaning only on a class. Remove the code, or move the attribute to the class.",
    );
})->with([
    'promoted parameter' => [PropertyStatusPromotedCode::class, 'status', '404'],
    'property' => [PropertyStatusDeclaredCode::class, 'status', '404'],
    'parameter' => [PropertyStatusParameterCode::class, 'status', '404'],
    'range' => [PropertyStatusRange::class, 'status', "'4xx'"],
    'default' => [PropertyStatusDefault::class, 'status', "'default'"],
]);

it('rejects a code in a property-level #[Status] when serializing', function (object $object) {
    expect(fn () => Serializer::serialize($object))->toThrow(RuntimeException::class, 'but a code has meaning only on a class');
})->with([
    'promoted parameter' => [fn () => new PropertyStatusPromotedCode],
    'property' => [fn () => new PropertyStatusDeclaredCode],
    'range' => [fn () => new PropertyStatusRange],
    'nested' => [fn () => new PropertyStatusNestedCode(new PropertyStatusPromotedCode)],
]);

it('rejects a code in a property-level #[Status] when sending a response', function () {
    expect(fn () => new PropertyStatusCodeOnResponse()->toResponse(new Request))
        ->toThrow(RuntimeException::class, 'but a code has meaning only on a class');
});

it('rejects a code in a property-level #[Status] when generating TypeScript', function () {
    expect(fn () => new TypeScriptGenerator([], [PropertyStatusCodeOnResponse::class])->generate())
        ->toThrow(RuntimeException::class, 'but a code has meaning only on a class');
});

it('accepts #[Status] without a code on a property', function () {
    expect(new PropertyStatusBare()->toResponse(new Request)->getStatusCode())->toBe(202)
        ->and(new PropertyStatusBareWithoutDefault(204)->toResponse(new Request)->getStatusCode())->toBe(204)
        ->and(new TypeScriptGenerator([], [PropertyStatusBare::class])->generate())->not->toContain('status');
});
