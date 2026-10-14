<?php

namespace BYanelli\Roma;

use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * The value of a property or parameter typed `T|Missing` when its key is absent from the
 * input. Such a type makes the property optional. The serializer omits a property with this
 * value.
 */
final class Missing
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * Whether a declared type is a union that includes Missing.
     */
    public static function isInType(?ReflectionType $type): bool
    {
        if (! $type instanceof ReflectionUnionType) {
            return false;
        }

        foreach ($type->getTypes() as $member) {
            if ($member instanceof ReflectionNamedType && $member->getName() === self::class) {
                return true;
            }
        }

        return false;
    }

    private function __clone() {}
}
