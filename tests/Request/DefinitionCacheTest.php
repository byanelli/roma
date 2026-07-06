<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Query;
use BYanelli\Roma\Tests\TestCase;

readonly class TestCachedDefinitionRequest
{
    #[Query]
    public string $name;

    #[Query]
    public int $page;
}

it('maps the same class twice from the memoized definition', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['name' => 'Bill', 'page' => '2']);

    $first = $this->mapRequest(TestCachedDefinitionRequest::class);

    // A second request for the same class reuses the cached definition and must
    // still map fresh request data correctly.
    $this->setRequest(query: ['name' => 'Sam', 'page' => '5']);

    $second = $this->mapRequest(TestCachedDefinitionRequest::class);

    expect($first->name)->toBe('Bill');
    expect($first->page)->toBe(2);
    expect($second->name)->toBe('Sam');
    expect($second->page)->toBe(5);
});
