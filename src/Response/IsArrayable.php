<?php

namespace BYanelli\Roma\Response;

use Illuminate\Contracts\Support\Arrayable;
use ReflectionObject;

/**
 * Serializes a response object's public properties to an array, recursing into
 * nested Arrayable values. Use on a class that implements Arrayable.
 */
trait IsArrayable
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach (new ReflectionObject($this)->getProperties() as $property) {
            if (! $property->isPublic()) {
                continue;
            }

            $value = $property->getValue($this);

            $result[$property->getName()] = $value instanceof Arrayable ? $value->toArray() : $value;
        }

        return $result;
    }
}
