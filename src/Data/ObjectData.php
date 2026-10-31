<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Data\Types\Class_;
use BYanelli\Roma\Missing;
use ReflectionProperty;

/**
 * The values of a constructed object as the array its validation rules expect: keyed by the
 * properties' key paths, with nested objects as arrays and a union member's discriminator. Other
 * values, such as dates and enums, are kept as they are.
 *
 * @internal
 */
final class ObjectData
{
    /**
     * @var array<array-key, mixed>
     */
    public private(set) array $data;

    /**
     * The paths of the objects that are the same instance as an object that contains them. Mapped
     * input never has one. Their values are null, and their members aren't read.
     *
     * @var list<KeyPath>
     */
    public private(set) array $cycles = [];

    public function __construct(Class_ $class, object $object)
    {
        $this->data = $this->object($class, $object, new KeyPath, [spl_object_id($object) => true]);
    }

    /**
     * Whether the object stores the property's value: a public property or a promoted
     * constructor parameter, not virtual or a class-level constraint.
     */
    public static function isReadable(Class_ $class, Property $property): bool
    {
        return match ($property->role) {
            Role::Property => ! $property->isVirtual,
            Role::Constructor => property_exists($class->class, $property->name)
                && new ReflectionProperty($class->class, $property->name)->isPromoted(),
            Role::ValidationOnly => false,
        };
    }

    /**
     * @param  array<int, true>  $ancestors  the spl_object_id()s of `$object` and the objects that contain it
     * @return array<array-key, mixed>
     */
    private function object(Class_ $class, object $object, KeyPath $path, array $ancestors): array
    {
        $data = [];

        foreach ($class->properties as $property) {
            if (! self::isReadable($class, $property)) {
                continue;
            }

            $reflection = new ReflectionProperty($class->class, $property->name);

            if (! $reflection->isInitialized($object) || ($value = $reflection->getValue($object)) instanceof Missing) {
                continue;
            }

            $property->keyPath->setIn($data, $this->value($property->type, $value, $path->concat($property->keyPath), $ancestors));
        }

        return $data;
    }

    /**
     * A value that isn't of the type, such as a string in a `list<int>`, is kept for the rules to reject.
     *
     * @param  array<int, true>  $ancestors
     */
    private function value(Type $type, mixed $value, KeyPath $path, array $ancestors): mixed
    {
        $isObject = $type instanceof Class_ && $value instanceof $type->class && ! $type->defersStringParsing();
        $isMember = $type instanceof Types\Union && is_object($value);

        if ($isObject || $isMember) {
            if (isset($ancestors[spl_object_id($value)])) {
                $this->cycles[] = $path;

                return null;
            }

            $ancestors[spl_object_id($value)] = true;
        }

        return match (true) {
            $isObject => $this->object($type, $value, $path, $ancestors),
            $isMember => $this->unionMember($type, $value, $path, $ancestors),
            $type instanceof Types\Array_ && is_array($value) => $this->elements($type, $value, $path, $ancestors),
            $type instanceof Types\Shape && is_array($value) => $this->shape($type, $value, $path, $ancestors),
            default => $value,
        };
    }

    /**
     * The member with the object's exact class, as the serializer chooses it.
     *
     * @param  array<int, true>  $ancestors
     */
    private function unionMember(Types\Union $union, object $value, KeyPath $path, array $ancestors): mixed
    {
        foreach ($union->members as $key => $member) {
            if ($member->class === $value::class) {
                return [$union->key => (string) $key, ...$this->object($member, $value, $path, $ancestors)];
            }
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<int, true>  $ancestors
     * @return array<array-key, mixed>
     */
    private function elements(Types\Array_ $array, array $value, KeyPath $path, array $ancestors): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = $this->value($array->memberType, $item, $path->append((string) $key), $ancestors);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<int, true>  $ancestors
     * @return array<array-key, mixed>
     */
    private function shape(Types\Shape $shape, array $value, KeyPath $path, array $ancestors): array
    {
        foreach ($shape->items as $item) {
            if (array_key_exists($item->key, $value)) {
                $value[$item->key] = $this->value($item->type, $value[$item->key], $path->append($item->key), $ancestors);
            }
        }

        return $value;
    }
}
