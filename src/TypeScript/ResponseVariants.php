<?php

namespace BYanelli\Roma\TypeScript;

use BYanelli\Roma\TypeScript\Types\Array_;
use BYanelli\Roma\TypeScript\Types\Enum;
use BYanelli\Roma\TypeScript\Types\Interface_;
use BYanelli\Roma\TypeScript\Types\Object_;
use BYanelli\Roma\TypeScript\Types\Record_;
use BYanelli\Roma\TypeScript\Types\Union;

/**
 * Finds the nested classes and union contracts whose request and response types differ, and
 * so need a separate response type. A class differs when its own properties differ (a default
 * makes a request property optional; a sensitive property is left out of responses) or when a
 * class it refers to differs. A union alias differs when one of its members does.
 *
 * @internal
 */
final readonly class ResponseVariants
{
    /**
     * @param  list<Interface_>  $requestInterfaces  the top-level request interfaces, built without variants
     * @param  list<Interface_>  $responseInterfaces  the top-level response interfaces, built without variants
     * @return array<class-string, true>
     */
    public static function find(array $requestInterfaces, array $responseInterfaces): array
    {
        $requestClasses = self::nestedClasses($requestInterfaces);
        $responseClasses = self::nestedClasses($responseInterfaces);

        $shared = array_intersect_key($responseClasses, $requestClasses);
        $differs = [];

        foreach ($shared as $class => $interface) {
            if (self::signature($interface) !== self::signature($requestClasses[$class])) {
                $differs[$class] = true;
            }
        }

        do {
            $changed = false;

            foreach (array_diff_key($shared, $differs) as $class => $interface) {
                foreach (self::references($interface) as $reference) {
                    if (isset($differs[$reference])) {
                        $differs[$class] = $changed = true;

                        break;
                    }
                }
            }
        } while ($changed);

        $requestAliases = self::aliases($requestInterfaces);

        foreach (self::aliases($responseInterfaces) as $contract => $members) {
            if (isset($requestAliases[$contract]) && array_intersect_key($differs, array_flip($members)) !== []) {
                $differs[$contract] = true;
            }
        }

        return $differs;
    }

    /**
     * The interfaces of the classes nested in the given top-level interfaces, by class.
     *
     * @param  list<Interface_>  $interfaces
     * @return array<class-string, Interface_>
     */
    private static function nestedClasses(array $interfaces): array
    {
        $classes = [];

        foreach ($interfaces as $interface) {
            // The empty interface inside a self-referencing class has the same unique key as
            // the full one, which flatten() returns instead.
            foreach (array_slice($interface->flatten(), 1) as $nested) {
                $classes[$nested->phpFqcn] ??= $nested;
            }
        }

        return $classes;
    }

    /**
     * The member classes of the union aliases the given interfaces refer to, by contract.
     *
     * @param  list<Interface_>  $interfaces
     * @return array<class-string, list<class-string>>
     */
    private static function aliases(array $interfaces): array
    {
        $aliases = [];

        foreach ($interfaces as $interface) {
            foreach ($interface->flatten() as $flattened) {
                foreach ($flattened->properties as $property) {
                    self::collectAliases($property->type, $aliases);
                }
            }
        }

        return $aliases;
    }

    /**
     * @param  array<class-string, list<class-string>>  $aliases
     */
    private static function collectAliases(Type $type, array &$aliases): void
    {
        if ($type instanceof Union && $type->contract !== null) {
            $aliases[$type->contract] ??= array_values(array_map(fn (Interface_ $member) => $member->phpFqcn, $type->members));
        } elseif ($type instanceof Array_) {
            self::collectAliases($type->memberType, $aliases);
        } elseif ($type instanceof Record_) {
            self::collectAliases($type->valueType, $aliases);
        } elseif ($type instanceof Object_) {
            foreach ($type->properties as $property) {
                self::collectAliases($property->type, $aliases);
            }
        }
    }

    /**
     * The classes an interface's properties refer to.
     *
     * @return list<class-string>
     */
    private static function references(Interface_ $interface): array
    {
        $references = [];

        foreach ($interface->properties as $property) {
            foreach (Interface_::referencedBy($property->type) as $referenced) {
                $references[] = $referenced->phpFqcn;
            }
        }

        return $references;
    }

    /**
     * An interface's properties as a string, with nested interfaces reduced to their class.
     */
    private static function signature(Interface_ $interface): string
    {
        return serialize(array_map(self::propertySignature(...), $interface->properties));
    }

    /**
     * @return list<mixed>
     */
    private static function propertySignature(Property $property): array
    {
        return [$property->key, $property->optional, $property->nullable, $property->description, self::typeSignature($property->type)];
    }

    private static function typeSignature(Type $type): mixed
    {
        return match (true) {
            $type instanceof Interface_ => [Interface_::class, $type->phpFqcn],
            $type instanceof Union => [Union::class, $type->key, $type->contract, array_map(fn (Interface_ $member) => $member->phpFqcn, $type->members)],
            $type instanceof Array_ => [Array_::class, self::typeSignature($type->memberType)],
            $type instanceof Record_ => [Record_::class, self::typeSignature($type->keyType), self::typeSignature($type->valueType)],
            $type instanceof Object_ => [Object_::class, array_map(self::propertySignature(...), $type->properties)],
            $type instanceof Enum => [Enum::class, $type->class],
            default => $type::class,
        };
    }
}
