<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Data\Reflection\ReflectionParamOrProperty;
use BYanelli\Roma\Http\Attributes\Query;

class TestAttributesWithTwoKeys
{
    #[Query('a'), Key('b')]
    public string $name;
}

it('keeps the property in collections derived from the attributes', function (Closure $derive) {
    $attributes = new ReflectionParamOrProperty(new ReflectionProperty(TestAttributesWithTwoKeys::class, 'name'))->attributes;

    expect(fn () => $derive($attributes)->assertCombinable())
        ->toThrow(RuntimeException::class, 'Roma property "TestAttributesWithTwoKeys::$name" gets a key from both #[Query] and #[Key]');
})->with([
    'filter' => [fn ($attributes) => $attributes->filter()],
    'map' => [fn ($attributes) => $attributes->map(fn (object $attribute) => $attribute)],
]);
