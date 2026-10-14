<?php

namespace BYanelli\Roma;

use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;

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
     *
     * @internal
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

    /**
     * Throws for a type with nothing but Missing and null, such as `?Missing`.
     *
     * @internal
     */
    public static function assertNotAlone(?ReflectionType $type, string $subject): void
    {
        $names = match (true) {
            $type instanceof ReflectionNamedType => [$type->getName()],
            $type instanceof ReflectionUnionType => array_map(fn (ReflectionType $member) => $member instanceof ReflectionNamedType ? $member->getName() : '', $type->getTypes()),
            default => [],
        };

        if (in_array(self::class, $names, true) && array_diff($names, [self::class, 'null']) === []) {
            throw new RuntimeException(
                "Roma property \"{$subject}\" has no type besides Missing. Add the type of its value, e.g. string|Missing."
            );
        }
    }

    private function __clone() {}
}
