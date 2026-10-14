<?php

namespace BYanelli\Roma\TypeScript\Types;

use BYanelli\Roma\TypeScript\Property;
use BYanelli\Roma\TypeScript\Type;

readonly class Interface_ extends Type
{
    public bool $isEmpty;

    public string $uniqueKey;

    /**
     * @param  list<Property>  $properties
     */
    public function __construct(
        public string $name,
        public array $properties,
        public string $phpFqcn,
    ) {
        $this->isEmpty = $this->properties === [];
        $this->uniqueKey = $this->phpFqcn.'/'.$this->name;
    }

    /**
     * Collect this interface and every nested Interface_ referenced by a
     * property type into a flat, de-duplicated list keyed by interface name.
     *
     * @param  array<string, true>  $seen
     * @return list<Interface_>
     */
    public function flatten(array &$seen = []): array
    {
        if (isset($seen[$this->name])) {
            return [];
        }

        $seen[$this->name] = true;

        $nested = [[]];

        foreach ($this->properties as $property) {
            foreach (self::referencedBy($property->type) as $interface) {
                $nested[] = $interface->flatten($seen);
            }
        }

        return [$this, ...array_merge(...$nested)];
    }

    /**
     * The interfaces a property type refers to by name, looking through arrays,
     * records and inline objects.
     *
     * @return list<Interface_>
     */
    private static function referencedBy(Type $type): array
    {
        return match (true) {
            $type instanceof self => [$type],
            $type instanceof Union => array_values($type->members),
            $type instanceof Array_ => self::referencedBy($type->memberType),
            $type instanceof Record_ => self::referencedBy($type->valueType),
            $type instanceof Object_ => array_merge(...array_map(fn (Property $p) => self::referencedBy($p->type), $type->properties)),
            default => [],
        };
    }
}
