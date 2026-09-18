<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Contracts on the request side
|--------------------------------------------------------------------------
|
| An interface or abstract class describes a shape the mapper cannot build:
| there is no constructor to call. That is a mistake in the request object, not
| bad input, so it must reach the developer instead of turning into a
| validation message.
*/

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
            'Cannot map request data into MappedThumbnailSource: it is an interface or abstract class.'
        );
});

abstract class MappedPaymentMethod {}

readonly class AbstractContractRequest
{
    public MappedPaymentMethod $method;
}

it('refuses to map request data into an abstract-class-typed property, even when absent', function () {
    // The property is never sent: the error is about the request object's own
    // declaration, so it must not wait for a client to supply the key.
    /** @var TestCase $this */
    $this->setRequest(query: []);

    expect(fn () => $this->mapRequest(AbstractContractRequest::class))
        ->toThrow(RuntimeException::class, 'Type the property with a concrete class.');
});
