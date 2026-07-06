<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Query;
use BYanelli\Roma\Request\Attributes\Rule;
use BYanelli\Roma\Request\Enums\Method;
use BYanelli\Roma\Tests\TestCase;

readonly class TestNestedQuerySourceChild
{
    #[Query]
    public string $value;
}

readonly class TestNestedQuerySourceRequest
{
    public TestNestedQuerySourceChild $child;
}

it('throws when a nested property declares an explicit source attribute', function () {
    /** @var TestCase $this */
    $this->setRequest();

    expect(fn () => $this->mapRequest(TestNestedQuerySourceRequest::class))
        ->toThrow(
            RuntimeException::class,
            'TestNestedQuerySourceChild::$value" is inside a nested request object and cannot declare its own source',
        );
});

readonly class TestNestedMetadataEnumChild
{
    public Method $method;
}

readonly class TestNestedMetadataEnumRequest
{
    public TestNestedMetadataEnumChild $child;
}

it('throws when a nested property is typed as a self-sourcing metadata enum', function () {
    /** @var TestCase $this */
    $this->setRequest();

    expect(fn () => $this->mapRequest(TestNestedMetadataEnumRequest::class))
        ->toThrow(
            RuntimeException::class,
            'TestNestedMetadataEnumChild::$method" is inside a nested request object and cannot declare its own source',
        );
});

readonly class TestNestedRuleChild
{
    #[Rule('min:2')]
    public string $name;
}

readonly class TestNestedRuleRequest
{
    public TestNestedRuleChild $child;
}

it('still allows non-source attributes like #[Rule] on nested properties', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['child' => ['name' => 'Bill']],
    );

    expect($this->mapRequest(TestNestedRuleRequest::class)->child->name)->toBe('Bill');
});
