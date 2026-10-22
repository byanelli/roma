<?php

namespace BYanelli\Roma\Data;

use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Whether a value fits a declared PHP type without coercion, as under strict_types.
 *
 * @internal
 */
final class DeclaredType
{
    /**
     * @param  class-string  $class  the class `self` and `static` refer to
     */
    public static function accepts(?ReflectionType $type, mixed $value, string $class): bool
    {
        if ($type === null || ($value === null && $type->allowsNull())) {
            return true;
        }

        return match (true) {
            $type instanceof ReflectionUnionType => array_any($type->getTypes(), fn (ReflectionType $member) => self::accepts($member, $value, $class)),
            $type instanceof ReflectionIntersectionType => array_all($type->getTypes(), fn (ReflectionType $member) => self::accepts($member, $value, $class)),
            $type instanceof ReflectionNamedType => self::acceptsNamed($type->getName(), $value, $class),
            default => true,
        };
    }

    /**
     * @param  class-string  $class
     */
    private static function acceptsNamed(string $name, mixed $value, string $class): bool
    {
        return match ($name) {
            'mixed' => true,
            'null' => $value === null,
            'int' => is_int($value),
            // An int is accepted for a float, as under strict_types.
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'true' => $value === true,
            'false' => $value === false,
            'array' => is_array($value),
            'iterable' => is_iterable($value),
            'object' => is_object($value),
            'callable' => is_callable($value),
            'self', 'static' => $value instanceof $class,
            default => $value instanceof $name,
        };
    }
}
