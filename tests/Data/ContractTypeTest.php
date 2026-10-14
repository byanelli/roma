<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\Tests\TestCase;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

interface MappedThumbnailSource {}

readonly class ContractRequest
{
    public MappedThumbnailSource $thumbnail;
}

it('refuses to map request data into an interface-typed property', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['thumbnail' => ['url' => 'https://example.com/a.png']]);

    expect(fn () => $this->mapRequest(ContractRequest::class))
        ->toThrow(
            RuntimeException::class,
            'Roma property "ContractRequest::$thumbnail" is typed as MappedThumbnailSource, an interface or abstract class. '
            .'Add #[Discriminated] to MappedThumbnailSource or to the property, or type the property with a concrete class.',
        );
});

abstract class MappedPaymentMethod {}

readonly class AbstractContractRequest
{
    public MappedPaymentMethod $method;
}

it('refuses to map request data into an abstract-class-typed property, even when absent', function () {
    /** @var TestCase $this */
    $this->setRequest(query: []);

    expect(fn () => $this->mapRequest(AbstractContractRequest::class))
        ->toThrow(RuntimeException::class, 'typed as MappedPaymentMethod, an interface or abstract class');
});

class ContractResponse extends Response
{
    public function __construct(public ?MappedThumbnailSource $thumbnail = null) {}
}

it('refuses to generate TypeScript for an interface-typed response property', function () {
    expect(fn () => new TypeScriptGenerator([], [ContractResponse::class])->generate())
        ->toThrow(RuntimeException::class, 'typed as MappedThumbnailSource, an interface or abstract class');
});

class ContractDatedResponse extends Response
{
    public function __construct(public DateTimeInterface $publishedAt) {}
}

it('treats DateTimeInterface as a date, not a contract', function () {
    expect(new TypeScriptGenerator([], [ContractDatedResponse::class])->generate())->toContain(<<<'TS'
        export interface ContractDatedResponseBody {
          publishedAt: string;
        }
        TS);
});
