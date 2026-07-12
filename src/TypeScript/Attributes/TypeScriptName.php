<?php

namespace BYanelli\Roma\TypeScript\Attributes;

use Attribute;
use ReflectionClass;

/**
 * Overrides the name of the TypeScript type generated for a class or enum.
 * Without it the short class name is used. Handy when the short name would
 * collide with another generated type or a hand-written one — e.g. an enum
 * named PlatformType renamed to PlatformTypeEnum.
 */
#[Attribute(Attribute::TARGET_CLASS)]
readonly class TypeScriptName
{
    public function __construct(public string $name) {}

    /**
     * The TypeScript name for a class/enum: its #[TypeScriptName] override if
     * present, otherwise the short class name.
     *
     * @param  class-string  $fqcn
     */
    public static function for(string $fqcn): string
    {
        $attributes = new ReflectionClass($fqcn)->getAttributes(self::class);

        return $attributes === []
            ? class_basename($fqcn)
            : $attributes[0]->newInstance()->name;
    }
}
