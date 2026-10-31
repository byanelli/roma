<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Data\Types\Array_;
use BYanelli\Roma\Data\Types\Class_;
use BYanelli\Roma\Data\Types\Shape;
use BYanelli\Roma\Data\Types\ShapeItem;
use BYanelli\Roma\Data\Types\Union;
use ReflectionClass;
use WeakMap;

/**
 * The objects Roma has built by mapping or passed through Mapper::validate(), with the Operation
 * each was validated under. A singleton, so the flag set by Mapper::requireValidation() in a
 * service provider applies to all requests in an Octane worker. Entries are removed when their
 * objects are garbage collected.
 */
final class ValidatedObjects
{
    /**
     * @var WeakMap<object, Operation>
     */
    private WeakMap $objects;

    private bool $required = false;

    /**
     * @var array<class-string, bool>
     */
    private array $requiredOnCreate = [];

    public function __construct()
    {
        $this->objects = new WeakMap;
    }

    public function record(object $object, Operation $operation): void
    {
        $this->objects[$object] = $operation;
    }

    /**
     * The Operation the object was validated under, or null when it wasn't validated. A lazy
     * object, such as an injected config object, is initialized first, which validates it.
     */
    public function operationOf(object $object): ?Operation
    {
        $class = new ReflectionClass($object);

        if ($class->isUninitializedLazyObject($object)) {
            $class->initializeLazyObject($object);
        }

        return $this->objects[$object] ?? null;
    }

    public function require(bool $require = true): void
    {
        $this->required = $require;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * Whether the class, or a class nested in it, has a #[RequiredOnCreate] property. Those are the
     * only properties that a Create requires and None doesn't.
     *
     * @param  class-string  $class
     *
     * @throws \ReflectionException
     */
    public function hasRequiredOnCreate(string $class): bool
    {
        $visited = [];

        return $this->requiredOnCreate[$class] ??= self::classHasRequiredOnCreate(
            new ClassDefinitionBuilder(new ObjectPolicy)->buildClassDefinition($class),
            $visited,
        );
    }

    /**
     * @internal
     */
    public function count(): int
    {
        return count($this->objects);
    }

    /**
     * @param  array<class-string, true>  $visited  the classes already checked, for recursive classes
     */
    private static function classHasRequiredOnCreate(Class_ $class, array &$visited): bool
    {
        if (isset($visited[$class->class])) {
            return false;
        }

        $visited[$class->class] = true;

        foreach ($class->properties as $property) {
            if ($property->requiredOnCreate || self::typeHasRequiredOnCreate($property->type, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<class-string, true>  $visited
     */
    private static function typeHasRequiredOnCreate(Type $type, array &$visited): bool
    {
        if ($type instanceof Class_) {
            return self::classHasRequiredOnCreate($type, $visited);
        }

        $members = match (true) {
            $type instanceof Union => $type->members,
            $type instanceof Array_ => [$type->memberType],
            $type instanceof Shape => array_map(fn (ShapeItem $item) => $item->type, $type->items),
            default => [],
        };

        foreach ($members as $member) {
            if (self::typeHasRequiredOnCreate($member, $visited)) {
                return true;
            }
        }

        return false;
    }
}
