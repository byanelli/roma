<?php

namespace BYanelli\Roma\Response;

use BackedEnum;
use BYanelli\Roma\Response\Attributes\Optional;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use ReflectionObject;
use ReflectionProperty;
use UnitEnum;

/**
 * Serializes a response object's public properties to an array, converting
 * common value types to their JSON form and recursing through nested response
 * objects, Arrayables, and arrays. Use on a class that implements Arrayable.
 */
trait IsArrayable
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach (new ReflectionObject($this)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            // An unset #[Optional] property is omitted. Any other unset property
            // has no implicit default, so accessing it below throws — surfacing
            // a response field that was never populated.
            if (! $property->isInitialized($this) && $property->getAttributes(Optional::class) !== []) {
                continue;
            }

            $result[$property->getName()] = $this->normalizeValue($property->getValue($this));
        }

        return $result;
    }

    private function normalizeValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof Arrayable => $this->normalizeValue($value->toArray()),
            is_array($value) => array_map($this->normalizeValue(...), $value),
            default => $value,
        };
    }
}
