<?php

namespace BYanelli\Roma\TypeScript\Types;

use BYanelli\Roma\TypeScript\Property;
use BYanelli\Roma\TypeScript\Type;

readonly class Interface_ extends Type
{
    /** Appended to the name of a nested class's response variant. */
    public const string RESPONSE_SUFFIX = 'Response';

    public bool $isEmpty;

    public string $uniqueKey;

    /**
     * @param  string  $name  the preferred name, without the response suffix
     * @param  list<Property>  $properties
     * @param  class-string  $phpFqcn
     * @param  bool  $isResponseVariant  whether this is the response type of a nested class whose request
     *                                   and response types differ
     */
    public function __construct(
        public string $name,
        public array $properties,
        public string $phpFqcn,
        public ?string $description = null,
        public bool $isResponseVariant = false,
    ) {
        $this->isEmpty = $this->properties === [];
        $this->uniqueKey = $this->phpFqcn.'/'.$this->name.($this->isResponseVariant ? '/response' : '');
    }

    /**
     * The preferred name, with the response suffix for a response variant.
     */
    public function preferredName(): string
    {
        return $this->name.($this->isResponseVariant ? self::RESPONSE_SUFFIX : '');
    }

    /**
     * Collect this interface and the nested Interface_ objects referenced by
     * property types into a flat list, de-duplicated by unique key.
     *
     * @param  array<string, true>  $seen
     * @return list<Interface_>
     */
    public function flatten(array &$seen = []): array
    {
        if (isset($seen[$this->uniqueKey])) {
            return [];
        }

        $seen[$this->uniqueKey] = true;

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
    public static function referencedBy(Type $type): array
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
