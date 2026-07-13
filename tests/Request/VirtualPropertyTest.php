<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Response\Response;
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
