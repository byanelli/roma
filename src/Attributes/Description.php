<?php

namespace BYanelli\Roma\Attributes;

use Attribute;
use ReflectionClass;

/**
 * A description of a class or property, used as the JSON Schema `description` and as a doc
 * comment in generated TypeScript.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Description
{
    public function __construct(public string $text) {}

    /**
     * @param  class-string  $class
     */
    public static function ofClass(string $class): ?string
    {
        $attribute = new ReflectionClass($class)->getAttributes(self::class)[0] ?? null;

        return $attribute?->newInstance()->text;
    }
}
